<?php

namespace Drupal\os2forms_rest_api;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\key_auth\Authentication\Provider\KeyAuth;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Webform helper for helping with webforms.
 */
class WebformHelper {
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user manager.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  private AccountProxyInterface $currentUser;

  /**
   * The key authentication service.
   *
   * @var \Drupal\key_auth\Authentication\Provider\KeyAuth
   */
  private KeyAuth $keyAuth;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private RequestStack $requestStack;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, AccountProxyInterface $currentUser, KeyAuth $keyAuth, RequestStack $requestStack) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->keyAuth = $keyAuth;
    $this->requestStack = $requestStack;
  }

  /**
   * Implements hook_webform_third_party_settings_form_alter().
   *
   * @phpstan-param array<string, mixed> $form
   */
  public function webformThirdPartySettingsFormAlter(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Entity\EntityForm $formObject */
    $formObject = $form_state->getFormObject();
    /** @var \Drupal\webform\WebformInterface $webform */
    $webform = $formObject->getEntity();

    $form['third_party_settings']['os2forms']['os2forms_rest_api'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => 'REST API',
      '#tree' => TRUE,
    ];

    $allowedUsers = $this->getAllowedUsers($webform);
    $form['third_party_settings']['os2forms']['os2forms_rest_api']['allowed_users'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#tags' => TRUE,
      '#title' => $this->t('Allowed users'),
      '#description' => $this->t("Limits users allowed to access this form's data via the REST API"),
      '#default_value' => $allowedUsers,
    ];

    $form['third_party_settings']['os2forms']['os2forms_rest_api']['api_info']['endpoints'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API endpoints'),

      'links' => [],

      'messages' => [
        '#markup' => $this->t('Share these endpoints with people that must will use the REST API. Authentification is required to access the endpoints.'),
      ],

    ];

    $routes = [
      'rest.webform_rest_elements.GET',
      'rest.webform_rest_fields.GET',
      'rest.webform_rest_submission.GET',
      'rest.webform_rest_all_form_submissions.GET',
    ];

    $requireUuid = static function ($route) {
      return in_array(
        $route,
        [
          'rest.webform_rest_submission.GET',
          'rest.webform_rest_submission.PATCH',
        ],
        TRUE
      );
    };

    $form['third_party_settings']['os2forms']['os2forms_rest_api']['api_info']['endpoints']['links']['#prefix'] = '<ol>';
    $form['third_party_settings']['os2forms']['os2forms_rest_api']['api_info']['endpoints']['links']['#suffix'] = '</ol>';

    foreach ($routes as $route) {
      $parameters = [];

      if ('rest.webform_rest_submit.POST' !== $route) {
        $parameters['webform_id'] = $webform->id();
      }
      $uuidPlaceholder = '{uuid}';
      if ($requireUuid($route)) {
        $parameters['uuid'] = $uuidPlaceholder;
      }

      $url = Url::fromRoute($route, $parameters, ['absolute' => TRUE]);
      $form['third_party_settings']['os2forms']['os2forms_rest_api']['api_info']['endpoints']['links'][$route] = [
        '#type' => 'link',
        '#title' => str_replace(urlencode($uuidPlaceholder), $uuidPlaceholder, $url->toString()),
        '#url' => $url,
        '#prefix' => '<li>',
        '#suffix' => '</li>',
      ];
    }

    if ($this->currentUser->isAuthenticated()) {
      /** @var \Drupal\user\Entity\User $apiUser */
      $apiUser = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      // Don't show API data links if current user is not included in
      // (non-empty) list of allowed users.
      if (!empty($allowedUsers) && !isset($allowedUsers[$apiUser->id()])) {
        $apiUser = NULL;
      }
      $apiKey = $apiUser ? $apiUser->api_key->value : NULL;
      if (!empty($apiKey)) {
        $form['third_party_settings']['os2forms']['os2forms_rest_api']['api_info']['endpoints_test'] = [
          '#type' => 'fieldset',
          '#title' => $this->t('Test API endpoints'),

          'links' => [],

          'message' => [
            '#markup' => $this->t('These are only for checking the API responses for user %user. <strong>Do not</strong> share these urls!', ['%user' => $apiUser->getAccountName()]),
          ],
        ];

        $form['third_party_settings']['os2forms']['os2forms_rest_api']['api_info']['endpoints_test']['links']['#prefix'] = '<ol>';
        $form['third_party_settings']['os2forms']['os2forms_rest_api']['api_info']['endpoints_test']['links']['#suffix'] = '</ol>';

        foreach ($routes as $route) {
          $parameters = [];

          if ('rest.webform_rest_submit.POST' !== $route) {
            $parameters['webform_id'] = $webform->id();
          }
          $uuidPlaceholder = '{uuid}';
          if ($requireUuid($route)) {
            $parameters['uuid'] = $uuidPlaceholder;
          }
          $parameters['api-key'] = $apiKey;

          $url = Url::fromRoute($route, $parameters, ['absolute' => TRUE]);
          $form['third_party_settings']['os2forms']['os2forms_rest_api']['api_info']['endpoints_test']['links'][$route] = [
            '#type' => 'link',
            '#title' => str_replace(urlencode($uuidPlaceholder), $uuidPlaceholder, $url->toString()),
            '#url' => $url,
            '#prefix' => '<li>',
            '#suffix' => '</li>',
          ];
        }
      }
    }
  }

  /**
   * Get webform by id or submission uuid.
   *
   * If submission uuid is specified (i.e. not null), the submission's webform's
   * id must match the specified webform id.
   *
   * @return \Drupal\webform\WebformInterface|null
   *   The webform if found.
   */
  public function getWebform(string $webformId, string $submissionUuid = NULL): ?WebformInterface {
    if (NULL !== $submissionUuid) {
      $storage = $this->entityTypeManager->getStorage('webform_submission');
      $submissionIds = $storage
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('uuid', $submissionUuid)
        ->execute();
      $submission = $storage->load(array_key_first($submissionIds));

      if (NULL === $submission) {
        return NULL;
      }

      assert($submission instanceof WebformSubmissionInterface);
      $webform = $submission->getWebform();
      if ($webformId !== $webform->id()) {
        return NULL;
      }

      return $webform;
    }

    return $this->entityTypeManager
      ->getStorage('webform')
      ->load($webformId);
  }

  /**
   * Get users allowed to access a webform's data.
   *
   * @return \Drupal\user\UserInterface[]|array
   *   The users.
   */
  private function getAllowedUsers(WebformInterface $webform): array {
    $settings = $webform->getThirdPartySetting('os2forms', 'os2forms_rest_api');
    $allowedUserIds = $settings['allowed_users'] ?? [];

    return $this->loadUsers($allowedUserIds);
  }

  /**
   * Check if a user has access to a webform.
   *
   * A user has access to a webform if the list of allowed users is empty or the
   * user is included in the list.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   * @param \Drupal\Core\Session\AccountInterface|int $user
   *   The user or user id.
   *
   * @return bool
   *   True if user has access to the webform.
   */
  public function hasWebformAccess(WebformInterface $webform, $user): bool {
    // AccountInterface::id() should return an `int` but actually returns a
    // `string`.
    $userId = (int) ($user instanceof AccountInterface ? $user->id() : $user);
    assert(is_int($userId));

    $allowedUsers = $this->getAllowedUsers($webform);

    return empty($allowedUsers) || isset($allowedUsers[$userId]);
  }

  /**
   * Load users.
   *
   * @phpstan-param array<int, mixed> $spec
   * @phpstan-return array<int, mixed>
   */
  private function loadUsers(array $spec): array {
    return $this->entityTypeManager
      ->getStorage('user')
      ->loadMultiple(array_column($spec, 'target_id'));
  }

  /**
   * Implements hook_file_download().
   *
   * Note: This is only used to deny access to a file that is attached to a
   * webform (submission) that the user does not have permission to access.
   * Permission to access private files are handled elsewhere.
   *
   * @phpstan-return int|array<string, string>|null
   */
  public function fileDownload(string $uri) {
    $request = $this->requestStack->getCurrentRequest();

    // We are only concerned with users authenticated via Key Auth (cf.
    // os2forms_rest_api.services.yml).
    if ($user = $this->keyAuth->authenticate($request)) {
      // Find webform id from uri, see example uri.
      // @Example: private://webform/some_webform_id/119/some_file_name.png
      $pattern = '/private:\/\/webform\/(?<webform>[^\/]*)/';
      if (preg_match($pattern, $uri, $matches)) {
        $webform = $this->getWebform($matches['webform']);
        if (NULL !== $webform) {
          // Deny access to file if user does not have access to the webform.
          if (!$this->hasWebformAccess($webform, $user)) {
            return -1;
          }
        }
      }
    }

    // We cannot deny access to the file. Let others handle the access control
    // for the (private) file.
    return NULL;
  }

  /**
   * Return current user.
   *
   * @return \Drupal\Core\Session\AccountProxyInterface
   *   The current user.
   */
  public function getCurrentUser(): AccountProxyInterface {
    return $this->currentUser;
  }

}
