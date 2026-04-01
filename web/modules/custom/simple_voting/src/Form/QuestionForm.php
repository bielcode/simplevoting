<?php

namespace Drupal\simple_voting\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulário unificado de criação e edição de enquetes.
 *
 * Responde pelos handlers "add" e "edit" da entidade VotingQuestion. As opções
 * de resposta vivem na tabela customizada simple_voting_option — são dados
 * transacionais, não parte da config — então persistimos via DBAL direto, fora
 * do ciclo do ConfigEntityStorage.
 */
class QuestionForm extends EntityForm {

  public function __construct(
    protected readonly Connection $database,
    protected readonly FileUsageInterface $fileUsage,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('file.usage'),
    );
  }

  // ---------------------------------------------------------------------------
  // Construção do formulário
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\simple_voting\Entity\VotingQuestion $question */
    $question = $this->entity;

    $form['title'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Título da enquete'),
      '#default_value' => $question->label(),
      '#required'      => TRUE,
      '#maxlength'     => 255,
    ];
    
    if ($question->isNew()) {
      $form['id'] = [
        '#type'         => 'machine_name',
        '#title'        => $this->t('Identificador único'),
        '#machine_name' => [
          'exists' => [$this, 'exists'],
          'source' => ['title'],
        ],
        '#maxlength'    => 64,
      ];
    }

    $this->initOptionsState($form_state);

    $count   = $form_state->get('options_count');
    $options = $form_state->get('existing_options');

    $form['options_wrapper'] = [
      '#type'   => 'fieldset',
      '#title'  => $this->t('Opções de resposta'),
      '#prefix' => '<div id="options-wrapper">',
      '#suffix' => '</div>',
    ];

    for ($i = 0; $i < $count; $i++) {
      $opt = $options[$i] ?? [];

      $form['options_wrapper'][$i] = [
        '#type'  => 'fieldset',
        '#title' => $this->t('Opção @n', ['@n' => $i + 1]),
      ];

      $form['options_wrapper'][$i]['option_id'] = [
        '#type'  => 'hidden',
        '#value' => $opt['id'] ?? NULL,
      ];

      $form['options_wrapper'][$i]['option_title'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Título'),
        '#default_value' => $opt['title'] ?? '',
        '#required'      => $i === 0,
        '#maxlength'     => 255,
      ];

      $form['options_wrapper'][$i]['option_description'] = [
        '#type'          => 'textarea',
        '#title'         => $this->t('Descrição'),
        '#default_value' => $opt['description'] ?? '',
        '#rows'          => 3,
      ];

      $form['options_wrapper'][$i]['option_image'] = [
        '#type'              => 'managed_file',
        '#title'             => $this->t('Imagem'),
        '#default_value'     => !empty($opt['image_fid']) ? [(int) $opt['image_fid']] : [],
        '#upload_location'   => 'public://simple_voting/options/',
        '#upload_validators' => [
          'file_validate_extensions' => ['png jpg jpeg gif webp'],
          'file_validate_size'       => [2 * 1024 * 1024],
        ],
      ];

      // Só exibe o botão de remoção a partir da segunda opção,
      // a primeira é obrigatória e não pode ser removida.
      if ($i > 0) {
        $form['options_wrapper'][$i]['remove'] = [
          '#type'                    => 'submit',
          '#value'                   => $this->t('Remover esta opção'),
          '#name'                    => 'remove_option_' . $i,
          '#submit'                  => ['::removeOptionRow'],
          '#ajax'                    => [
            'callback' => '::refreshOptionsWrapper',
            'wrapper'  => 'options-wrapper',
          ],
          '#limit_validation_errors' => [],
          '#option_index'            => $i,
        ];
      }
    }

    $form['options_wrapper']['add_option'] = [
      '#type'                    => 'submit',
      '#value'                   => $this->t('+ Adicionar opção'),
      '#submit'                  => ['::addOptionRow'],
      '#ajax'                    => [
        'callback' => '::refreshOptionsWrapper',
        'wrapper'  => 'options-wrapper',
      ],
      '#limit_validation_errors' => [],
    ];

    $form['show_results'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Exibir total de votos após a votação'),
      '#default_value' => $question->showsResults(),
      '#description'   => $this->t(
        'Quando marcado, o total de votos de cada opção será exibido ao usuário imediatamente após ele votar. '
        . 'Quando desmarcado, o resultado permanecerá oculto.'
      ),
    ];

    $form['status'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Status'),
      '#options'       => [
        1 => $this->t('Ativo'),
        0 => $this->t('Inativo'),
      ],
      '#default_value' => (int) $question->status(),
    ];

    return $form;
  }

  // ---------------------------------------------------------------------------
  // Handlers AJAX
  // ---------------------------------------------------------------------------

  /**
   * Acrescenta uma linha em branco ao final da lista de opções.
   */
  public function addOptionRow(array &$form, FormStateInterface $form_state): void {
    $count = $form_state->get('options_count');

    // Salva os valores que o usuário já digitou antes de reconstruir o form.
    $form_state->set('existing_options', $this->collectCurrentOptions($count, $form_state));
    $form_state->set('options_count', $count + 1);
    $form_state->setRebuild();
  }

  /**
   * Remove a linha cujo índice está em #option_index do botão acionado.
   */
  public function removeOptionRow(array &$form, FormStateInterface $form_state): void {
    $index   = $form_state->getTriggeringElement()['#option_index'];
    $count   = $form_state->get('options_count');
    $current = $this->collectCurrentOptions($count, $form_state);

    array_splice($current, $index, 1);

    $form_state->set('existing_options', $current);
    $form_state->set('options_count', max(1, $count - 1));
    $form_state->setRebuild();
  }

  /**
   * Callback AJAX — devolve o wrapper das opções para substituição no DOM.
   */
  public function refreshOptionsWrapper(array &$form, FormStateInterface $form_state): array {
    return $form['options_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    $entity->set('title', $form_state->getValue('title'));
    $entity->set('show_results', (bool) $form_state->getValue('show_results'));

    $form_state->getValue('status') ? $entity->open() : $entity->close();

    if ($entity->isNew() && $form_state->getValue('id')) {
      $entity->set('id', $form_state->getValue('id'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\simple_voting\Entity\VotingQuestion $question */
    $question = $this->entity;

    $result = $question->save();

    $this->persistOptions($question->id(), $form_state);

    $label = $question->label();
    $this->messenger()->addStatus(
      $result === SAVED_NEW
        ? $this->t('Enquete %label criada com sucesso.', ['%label' => $label])
        : $this->t('Enquete %label atualizada com sucesso.', ['%label' => $label])
    );

    $form_state->setRedirectUrl($question->toUrl('collection'));

    return $result;
  }

  /**
   * Verifica se um machine name já está em uso pelo elemento #machine_name.
   */
  public function exists(string $id): bool {
    return (bool) $this->entityTypeManager
      ->getStorage('voting_question')
      ->load($id);
  }

  /**
   * Inicializa o estado das opções na primeira renderização do formulário.
   */
  private function initOptionsState(FormStateInterface $form_state): void {
    if ($form_state->get('options_count') !== NULL) {
      return;
    }

    $question = $this->entity;
    $existing = $question->isNew() ? [] : $this->loadOptions($question->id());

    $form_state->set('existing_options', array_values($existing));
    $form_state->set('options_count', max(1, count($existing)));
  }

  /**
   * Lê os valores atuais das linhas de opção direto do form_state.
   */
  private function collectCurrentOptions(int $count, FormStateInterface $form_state): array {
    $options = [];
    for ($i = 0; $i < $count; $i++) {
      $fids = $form_state->getValue(['options_wrapper', $i, 'option_image']) ?: [];
      $options[] = [
        'id'          => $form_state->getValue(['options_wrapper', $i, 'option_id']),
        'title'       => $form_state->getValue(['options_wrapper', $i, 'option_title']) ?? '',
        'description' => $form_state->getValue(['options_wrapper', $i, 'option_description']) ?? '',
        'image_fid'   => is_array($fids) ? ($fids[0] ?? NULL) : NULL,
      ];
    }
    return $options;
  }

  /**
   * Carrega as opções de uma questão ordenadas por peso.
   */
  private function loadOptions(string $question_id): array {
    return $this->database
      ->select('simple_voting_option', 'o')
      ->fields('o', ['id', 'title', 'description', 'image_fid', 'weight'])
      ->condition('o.question_id', $question_id)
      ->orderBy('o.weight')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Sincroniza as opções submetidas com o banco de dados.
   */
  private function persistOptions(string $question_id, FormStateInterface $form_state): void {
    $count     = $form_state->get('options_count');
    $submitted = [];

    for ($i = 0; $i < $count; $i++) {
      $title = trim($form_state->getValue(['options_wrapper', $i, 'option_title']) ?? '');
      if ($title === '') {
        continue;
      }

      $fids = $form_state->getValue(['options_wrapper', $i, 'option_image']) ?: [];
      $submitted[] = [
        'id'          => $form_state->getValue(['options_wrapper', $i, 'option_id']) ?: NULL,
        'title'       => $title,
        'description' => $form_state->getValue(['options_wrapper', $i, 'option_description']) ?? '',
        'image_fid'   => is_array($fids) ? ($fids[0] ?? NULL) : NULL,
        'weight'      => $i,
      ];
    }

    $existing_ids  = array_column($this->loadOptions($question_id), 'id');
    $submitted_ids = array_filter(array_column($submitted, 'id'));
    $deleted_ids   = array_diff($existing_ids, $submitted_ids);

    if ($deleted_ids) {
      $this->releaseOptionFiles(array_values($deleted_ids));

      $this->database->delete('simple_voting_vote')
        ->condition('option_id', $deleted_ids, 'IN')
        ->execute();

      $this->database->delete('simple_voting_option')
        ->condition('id', $deleted_ids, 'IN')
        ->execute();
    }

    foreach ($submitted as $opt) {
      $record = [
        'question_id' => $question_id,
        'title'       => $opt['title'],
        'description' => $opt['description'],
        'image_fid'   => $opt['image_fid'],
        'weight'      => $opt['weight'],
      ];

      if ($opt['id']) {
        $this->handleFileUsageUpdate((int) $opt['id'], $opt['image_fid']);
        $this->database->update('simple_voting_option')
          ->fields($record)
          ->condition('id', $opt['id'])
          ->execute();
      }
      else {
        $new_id = (int) $this->database->insert('simple_voting_option')
          ->fields($record)
          ->execute();
        $this->attachFile($new_id, $opt['image_fid']);
      }
    }
  }

  /**
   * Registra uso de arquivo para uma opção recém-inserida.
   */
  private function attachFile(int $option_id, ?int $fid): void {
    if (!$fid) {
      return;
    }

    /** @var \Drupal\file\FileInterface|null $file */
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file) {
      return;
    }

    $file->setPermanent();
    $file->save();
    $this->fileUsage->add($file, 'simple_voting', 'voting_option', $option_id);
  }

  /**
   * Compara o FID atual no banco com o novo e ajusta o registro de uso.
   *
   * Lê o valor antigo ANTES do UPDATE para não comparar o fid consigo mesmo.
   */
  private function handleFileUsageUpdate(int $option_id, ?int $new_fid): void {
    $old_fid = (int) $this->database
      ->select('simple_voting_option', 'o')
      ->fields('o', ['image_fid'])
      ->condition('o.id', $option_id)
      ->execute()
      ->fetchField();

    if ($old_fid === (int) $new_fid) {
      return;
    }

    if ($old_fid) {
      $old_file = $this->entityTypeManager->getStorage('file')->load($old_fid);
      if ($old_file) {
        $this->fileUsage->delete($old_file, 'simple_voting', 'voting_option', $option_id);
      }
    }

    $this->attachFile($option_id, $new_fid);
  }

  /**
   * Libera o registro de uso de todos os arquivos das opções removidas.
   */
  private function releaseOptionFiles(array $option_ids): void {
    $rows = $this->database
      ->select('simple_voting_option', 'o')
      ->fields('o', ['id', 'image_fid'])
      ->condition('o.id', $option_ids, 'IN')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
      if (!$row['image_fid']) {
        continue;
      }
      $file = $this->entityTypeManager->getStorage('file')->load((int) $row['image_fid']);
      if ($file) {
        $this->fileUsage->delete($file, 'simple_voting', 'voting_option', (int) $row['id']);
      }
    }
  }

}
