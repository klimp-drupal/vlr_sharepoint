<?php

namespace Drupal\vlr_sharepoint\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Serialization\Yaml;
use Drupal\language\ConfigurableLanguageManager;
use Drupal\vlr_sharepoint\SharepointHelper;
use Drupal\webform\Plugin\WebformElementManagerInterface;
use Drupal\webform\Plugin\WebformHandler\RemotePostWebformHandler;
use Drupal\webform\WebformMessageManagerInterface;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform submission remote post handler.
 *
 * @WebformHandler(
 *   id = "vlr_remote_post",
 *   label = @Translation("VLR Remote post"),
 *   category = @Translation("External"),
 *   description = @Translation("VLR. Posts webform submissions to a URL."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE,
 * )
 */
class VlrRemotePostWebformHandler extends RemotePostWebformHandler implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\vlr_sharepoint\SharepointHelper
   */
  protected $sharepointHelper;

  /**
   * @var \Drupal\language\ConfigurableLanguageManager
   */
  protected $languageManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('module_handler'),
      $container->get('http_client'),
      $container->get('webform.token_manager'),
      $container->get('webform.message_manager'),
      $container->get('plugin.manager.webform.element'),
      $container->get('vlr_sharepoint.sharepoint_helper'),
      $container->get('language_manager')
    );

    $instance->request = $container->get('request_stack')->getCurrentRequest();
    $instance->kernel = $container->get('kernel');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    WebformSubmissionConditionsValidatorInterface $conditions_validator,
    ModuleHandlerInterface $module_handler,
    ClientInterface $http_client,
    WebformTokenManagerInterface $token_manager,
    WebformMessageManagerInterface $message_manager,
    WebformElementManagerInterface $element_manager,
    SharepointHelper $sharepoint_helper,
    ConfigurableLanguageManager $language_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator, $module_handler, $http_client, $token_manager, $message_manager, $element_manager);
    $this->sharepointHelper = $sharepoint_helper;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['sharepoint'] = [
      '#type' => 'details',
      '#title' => $this->t('Sharepoint'),
    ];
    $form['sharepoint']['sharepoint_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Sharepoint credentials Key'),
      '#default_value' => $this->configuration['sharepoint_key'] ?: NULL,
      '#required' => TRUE,
    ];
    $form['sharepoint']['sharepoint_list'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sharepoint list name'),
      '#default_value' => $this->configuration['sharepoint_list'] ?: NULL,
      '#required' => TRUE,
    ];
    $form['sharepoint']['sharepoint_mapping'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#element_validate' => ['::validateElementsYaml'],
      '#attributes' => ['style' => 'min-height: 300px'],
      '#title' => $this->t('Mapping'),
      '#default_value' => $this->configuration['sharepoint_mapping'] ?: NULL,
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $sharepoint = $form_state->getValue('sharepoint');
    $this->configuration['sharepoint_list'] = $sharepoint['sharepoint_list'];
    $this->configuration['sharepoint_key'] = $sharepoint['sharepoint_key'];
    $this->configuration['sharepoint_mapping'] = $sharepoint['sharepoint_mapping'];
  }

  /**
   * Execute a remote post.
   *
   * @param string $state
   *   The state of the webform submission.
   *   Either STATE_NEW, STATE_DRAFT_CREATED, STATE_DRAFT_UPDATED,
   *   STATE_COMPLETED, STATE_UPDATED, or STATE_CONVERTED
   *   depending on the last save operation performed.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   *
   * @throws \Exception
   */
  protected function remotePost($state, WebformSubmissionInterface $webform_submission) {
    $state_url = $state . '_url';
    if (empty($this->configuration[$state_url])) {
      return;
    }

    $this->sharepointHelper->setUrl($this->configuration[$state_url]);
    try {
      $this->sharepointHelper->setUser($this->configuration['sharepoint_key']);
    }
    catch (\Exception $e) {
      // Set form error message.
      $this->messenger()->addError($e->getMessage());

      // Exception if Ajax, refresh the page otherwise.
      if ($this->getWebform()->getSetting('ajax')) throw $e;
      $this->redirectError();
      return;
    }

    // Get Sharepoint auth cookies and digest.
    $auth_data = $this->sharepointHelper->getAuthData();

    $custom_options['curl'] = [
      CURLOPT_HTTPHEADER => [
        'X-RequestDigest: ' . $auth_data['digest'],
        'Content-Type: application/json;odata=verbose',
      ],
      CURLOPT_COOKIE => implode(';', $auth_data['cookies'])
    ];
    $this->configuration['custom_options'] = Yaml::encode($custom_options);

    $new_data = [
      // Needed for 'odata=verbose' Content-Type header.
      '__metadata' => [
        'type' => 'SP.Data.' . $this->configuration['sharepoint_list'],
      ],

      'ReferralFormID' => $webform_submission->get('uuid')->getString(),
      // TODO: $webform->getLangcode() doesn't work.
      'Language' => $this->languageManager->getCurrentLanguage()->getId(),
    ];

    // Sharepoint mapping.
    $mapping = Yaml::decode($this->configuration['sharepoint_mapping']);

    // Webform elements information.
    $elements = $this->getWebform()->getElementsDecodedAndFlattened();

    foreach ($webform_submission->getData() as $key => $val) {
      if (gettype($val) == 'string') $val = trim($val);

      // TRUE/FALSE for checkbox instead of 0/1.
      if ($elements[$key]['#type'] == 'checkbox') $val = $val ? TRUE : FALSE;

      // Assign new Sharepoint key.
      if (isset($mapping[$key])) $new_data[$mapping[$key]] = $val;
    }

    // Alter data in hook.
    \Drupal::moduleHandler()->alter('vlr_sharepoint_webform_data', $new_data, $this->getWebform());

    $webform_submission->setData($new_data);

    parent::remotePost($state, $webform_submission);
  }

  /**
   * Refreshes the page on handler error.
   *
   * The redirect part is taken from RemotePostWebformHandler::handleError()
   *
   * @see handleError()
   */
  protected function redirectError() {
    $current_url = $this->request->getUri();
    $response = new TrustedRedirectResponse($current_url);
    // Save the session so things like messages get saved.
    $this->request->getSession()->save();
    $response->prepare($this->request);
    // Make sure to trigger kernel events.
    $this->kernel->terminate($this->request, $response);
    $response->send();
  }

}
