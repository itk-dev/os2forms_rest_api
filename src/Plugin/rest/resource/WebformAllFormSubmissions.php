<?php

namespace Drupal\os2forms_rest_api\Plugin\rest\resource;

use Drupal\Core\Url;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Creates a resource for retrieving webform submission data and fields.
 *
 * @RestResource(
 *   id = "webform_rest_all_form_submissions",
 *   label = @Translation("Webform - All submissions for a form"),
 *   uri_paths = {
 *     "canonical" = "/webform_rest/{webform_id}/all"
 *   }
 * )
 */
class WebformAllFormSubmissions extends ResourceBase {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  private $currentRequest;

  /**
   * The entity type manager object.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  private $entityTypeManager;

  /**
   * The webform helper.
   *
   * @var \Drupal\os2forms_rest_api\WebformHelper
   */
  private $webformHelper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->setCurrentRequest($container->get('request_stack')->getCurrentRequest());
    $instance->webformHelper = $container->get('Drupal\os2forms_rest_api\WebformHelper');

    return $instance;
  }

  /**
   * Sets the current request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $current_request
   *   The current request.
   *
   * @return $this
   *   Class.
   */
  protected function setCurrentRequest(Request $current_request) {
    $this->currentRequest = $current_request;
    return $this;
  }

  /**
   * Retrieve all submissions for a given webform id.
   *
   * @param string $webform_id
   *   Webform ID.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   HTTP response object containing webform submissions.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws HttpException in case of error.
   */
  public function get(string $webform_id): ModifiedResourceResponse {
    if (empty($webform_id)) {
      $errors = [
        'error' => [
          'message' => 'Webform ID is required.',
        ],
      ];
      return new ModifiedResourceResponse($errors, 400);
    }

    // Webform access check.
    $webform = $this->webformHelper->getWebform($webform_id);

    if (NULL === $webform) {
      $errors = [
        'error' => [
          'message' => $this->t('Could not find webform with id :webform_id', [':webform_id' => $webform_id]),
        ],
      ];
      return new ModifiedResourceResponse($errors, 400);
    }

    if (!$this->webformHelper->hasWebformAccess($webform, $this->webformHelper->getCurrentUser())) {
      $errors = [
        'error' => [
          'message' => $this->t('Access denied'),
        ],
      ];
      return new ModifiedResourceResponse($errors, 401);
    }

    $submissionData = [];

    $result = ['webform_id' => $webform_id];

    // Query for webform submissions with this webform_id.
    $query = $this->entityTypeManager->getStorage('webform_submission')->getQuery()
      ->condition('webform_id', $webform_id);

    $startTimestamp = $this->currentRequest->query->get('starttime');
    if (is_numeric($startTimestamp)) {
      $query->condition('created', $startTimestamp, '>=');
      $result['starttime'] = $startTimestamp;
    }

    $endTimestamp = $this->currentRequest->query->get('endtime');
    if (is_numeric($endTimestamp)) {
      $query->condition('created', $endTimestamp, '<=');
      $result['endtime'] = $endTimestamp;
    }

    $query->accessCheck(FALSE);
    $sids = $query->execute();

    foreach ($sids as $sid) {
      /** @var \Drupal\webform\WebformSubmissionInterface $webform_submission */
      $webform_submission = $this->entityTypeManager->getStorage('webform_submission')->load($sid);

      $url = Url::fromRoute(
        'rest.webform_rest_submission.GET',
        [
          'webform_id' => $webform_id,
          'uuid' => $webform_submission->uuid(),
        ],
        [
          'absolute' => TRUE,
        ]
      )->toString();

      $submissionData[$sid] = $url;
    }

    $result['submissions'] = $submissionData;

    return new ModifiedResourceResponse($result);
  }

}
