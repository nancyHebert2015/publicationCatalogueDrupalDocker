<?php

namespace Drupal\page_manager_ui\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\ContextAwarePluginAssignmentTrait;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a base form for configuring a block as part of a variant.
 */
abstract class VariantPluginConfigureBlockFormBase extends FormBase {

  use ContextAwarePluginAssignmentTrait;

  /**
   * Tempstore factory.
   *
   * @var \Drupal\Core\TempStore\SharedTempStoreFactory
   */
  protected $tempstore;

  /**
   * The variant plugin.
   *
   * @var \Drupal\page_manager\Plugin\DisplayVariant\PageBlockDisplayVariant
   */
  protected $variantPlugin;

  /**
   * The plugin being configured.
   *
   * @var \Drupal\Core\Block\BlockPluginInterface
   */
  protected $block;

  /**
   * Constructs a new VariantPluginConfigureBlockFormBase.
   *
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $tempstore
   *   The tempstore factory.
   */
  public function __construct(SharedTempStoreFactory $tempstore) {
    $this->tempstore = $tempstore;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tempstore.shared')
    );
  }

  /**
   * Get the tempstore id.
   *
   * @return string
   *   The temp store id.
   */
  protected function getTempstoreId() {
    return 'page_manager.block_display';
  }

  /**
   * Get the tempstore.
   *
   * @return \Drupal\Core\TempStore\SharedTempStore
   *   The shared temp store.
   */
  protected function getTempstore() {
    return $this->tempstore->get($this->getTempstoreId());
  }

  /**
   * Prepares the block plugin based on the block ID.
   *
   * @param string $block_id
   *   Either a block ID, or the plugin ID used to create a new block.
   *
   * @return \Drupal\Core\Block\BlockPluginInterface
   *   The block plugin.
   */
  abstract protected function prepareBlock($block_id);

  /**
   * Returns the text to use for the submit button.
   *
   * @return string
   *   The submit button text.
   */
  abstract protected function submitText();

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $block_display = NULL, $block_id = NULL) {
    $cached_values = $this->tempstore->get('page_manager.block_display')->get($block_display);
    /** @var \Drupal\page_manager\Plugin\DisplayVariant\PageBlockDisplayVariant $variant_plugin */
    $this->variantPlugin = $cached_values['plugin'];

    // Rehydrate the contexts on this end.
    $contexts = [];
    /**
     * @var string $context_name
     * @var \Drupal\Core\Plugin\Context\ContextDefinitionInterface $context_definition
     */
    foreach ($cached_values['contexts'] as $context_name => $context_definition) {
      $contexts[$context_name] = new Context($context_definition);
    }
    $this->variantPlugin->setContexts($contexts);

    $this->block = $this->prepareBlock($block_id);
    $form_state->set('variant_id', $this->getVariantPlugin()->id());
    $form_state->set('block_id', $this->block->getConfiguration()['uuid']);

    $form['#tree'] = TRUE;
    $form['settings'] = $this->block->buildConfigurationForm([], $form_state);
    $form['settings']['id'] = [
      '#type' => 'value',
      '#value' => $this->block->getPluginId(),
    ];
    $form['region'] = [
      '#title' => $this->t('Region'),
      '#type' => 'select',
      '#options' => $this->getVariantPlugin()->getRegionNames(),
      '#default_value' => $this->getVariantPlugin()->getRegionAssignment($this->block->getConfiguration()['uuid']),
      '#required' => TRUE,
    ];

    if ($this->block instanceof ContextAwarePluginInterface) {
      $form['context_mapping'] = $this->addContextAssignmentElement($this->block, $this->getVariantPlugin()->getContexts());
    }

    $settings = $this->block->getConfiguration();
    $form['css_classes'] = [
      '#title' => $this->t('Css classes'),
      '#type' => 'textfield',
      '#default_value' => !empty($settings['css_classes']) ? $settings['css_classes'] : NULL,
    ];

    $form['css_id'] = [
      '#title' => $this->t('Css ID'),
      '#type' => 'textfield',
      '#default_value' => !empty($settings['css_id']) ? $settings['css_id'] : NULL,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->submitText(),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // The page might have been serialized, resulting in a new variant
    // collection. Refresh the block object.
    $this->block = $this->getVariantPlugin()->getBlock($form_state->get('block_id'));

    $settings = (new FormState())->setValues($form_state->getValue('settings'));
    // Call the plugin validate handler.
    $this->block->validateConfigurationForm($form, $settings);
    // Update the original form values.
    $form_state->setValue('settings', $settings->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $settings = (new FormState())->setValues($form_state->getValue('settings'));

    // Call the plugin submit handler.
    $this->block->submitConfigurationForm($form, $settings);
    // Update the original form values.
    $form_state->setValue('settings', $settings->getValues());

    if ($this->block instanceof ContextAwarePluginInterface) {
      $this->block->setContextMapping($form_state->getValue('context_mapping', []));
    }

    $block_settings = [
      'region' => $form_state->getValue('region'),
      'css_classes' => $form_state->getValue('css_classes'),
      'css_id' => $form_state->getValue('css_id'),
    ];
    $this->getVariantPlugin()->updateBlock($this->block->getConfiguration()['uuid'], $block_settings);

    $cached_values = $this->getTempstore()->get($form_state->get('variant_id'));
    $cached_values['plugin'] = $this->getVariantPlugin();
    $this->getTempstore()->set($form_state->get('variant_id'), $cached_values);
  }

  /**
   * Gets the variant plugin for this page variant entity.
   *
   * @return \Drupal\ctools\Plugin\BlockVariantInterface
   *   The variant plugin.
   */
  protected function getVariantPlugin() {
    return $this->variantPlugin;
  }

}
