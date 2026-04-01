<?php

namespace Drupal\simple_voting\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Formulário de configurações globais do módulo Simple Voting.
 *
 * Controla flags que afetam o comportamento de todos os recursos do módulo,
 * como habilitar ou suspender o recebimento de votos globalmente.
 */
class VotingSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['simple_voting.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'simple_voting_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('simple_voting.settings');

    $form['voting_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Habilitar votações'),
      '#description' => $this->t('Quando desmarcado, nenhuma enquete aceita novos votos, independente do status individual de cada pergunta.'),
      '#default_value' => $config->get('voting_enabled'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('simple_voting.settings')
      ->set('voting_enabled', (bool) $form_state->getValue('voting_enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
