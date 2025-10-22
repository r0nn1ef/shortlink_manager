<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Utility\Token;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the utm parameter definition entity edit forms.
 * * This form is used by the administrator/marketer to create reusable
 * parameter definitions (e.g., 'Source: Facebook' with Key='utm_source' and
 * Value='facebook').
 */
final class UtmParameterDefinitionForm extends ContentEntityForm {

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected Token $token;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    Token $token,
    RendererInterface $renderer
  ) {
    // Pass core services to the parent ContentEntityForm constructor.
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);

    // Inject custom services.
    $this->token = $token;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    // Override create() to ensure all required services are injected.
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('token'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Call the parent to get all fields rendered, including 'field_parameter_value'.
    $form = parent::buildForm($form, $form_state);

    if ( isset($form['created']) ) {
      $form['created']['#access'] = FALSE;
    }
    if ( isset($form['status']) ) {
      $form['status']['#access'] = FALSE;
    }

    // --- Add Token Browser Link to the Value Field ---
    // The field machine name is 'field_parameter_value'.
    if (isset($form['field_parameter_value'])) {

      // Target the actual input element: [0]['value'] for a single-value text field.
      $element = &$form['field_parameter_value']['widget'][0]['value'];
//      header('content-type: text/plain');
//      var_dump($element);
//      exit;

      // --- 1. Define available token types ---
      // These are the token groups the marketer is likely to need.
      $token_types = ['node', 'vocabulary', 'term', 'user', 'site'];

      // --- 2. Build the Token Tree Link render array ---
      $token_link_render_array = [
        '#theme' => 'token_tree_link',
        '#token_types' => $token_types,
        '#global_types' => TRUE,
      ];

      // --- 3. Render and Attach the link to the description ---
      // We render the link array to a string using the renderer service.
      $token_link_markup = $this->renderer->render($token_link_render_array);

      // Append the token link markup to the field's existing description.
      // Using <br> ensures it drops to a new line for better visibility.
      $element['#description'] = ($element['#description'] ?? '') . '<br>' . $this->t('This field supports tokens. Tokens will be replaced before redirection.') . ' ' . $token_link_markup;

      // Ensure the description is rendered as markup (HTML) to process the link.
      $element['#description_display'] = 'after';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $message_args = ['%label' => $this->entity->toLink()->toString()];
    $logger_args = [
      '%label' => $this->entity->label(),
      'link' => $this->entity->toLink($this->t('View'))->toString(),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('New utm parameter definition %label has been created.', $message_args));
        $this->logger('shortlink_manager')->notice('New utm parameter definition %label has been created.', $logger_args);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The utm parameter definition %label has been updated.', $message_args));
        $this->logger('shortlink_manager')->notice('The utm parameter definition %label has been updated.', $logger_args);
        break;

      default:
        // This catch-all ensures we don't have an unexpected exit state.
        throw new \LogicException('Could not save the entity.');
    }

    // Redirect to the newly saved entity's view page.
    $form_state->setRedirectUrl($this->entity->toUrl());

    return $result;
  }

}
