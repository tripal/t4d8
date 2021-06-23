<?php

namespace Drupal\tripal_chado\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Class ChadoInstallForm.
 */
class ChadoInstallForm extends FormBase {

  /**
   * Form action names.
   */
  /**
   * @defgroup chado_install_form_actions Form action names.
   * @{
   * Names used to identify form actions in Chado installation form.
   */
  /**
   * Install Chado v1.3 action identifier.
   */
  public const INSTALL_13_ACTION = 'Install Chado v1.3';
  /**
   * Import Chado action identifier.
   */
  public const IMPORT_ACTION     = 'Import Chado Schema';
  /**
   * Clone Chado action identifier.
   */
  public const CLONE_ACTION      = 'Clone Chado Schema';
  /**
   * Drop Chado action identifier.
   */
  public const DROP_ACTION       = 'Drop Chado Schema';
  /**
   * @} End of "defgroup chado_install_form_actions".
   */

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chado_install_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $values = $form_state->getValues();
    // Add warnings to the admin based on their choice (as needed).
    if (array_key_exists('action_to_do', $values)) {
      if ($values['action_to_do'] == INSTALL_13_ACTION) {
        \Drupal::messenger()->addMessage(
            'Please note: if Chado is already installed it will
            be removed and recreated and all data will be lost. If this is
            desired or if this is the first time Chado has been installed
            you can ignore this issue.', 'warning');
      }
      elseif ($values['action_to_do'] == DROP_ACTION) {
        \Drupal::messenger()->addMessage(
            'Please note: all data will be lost in the schema you choose to
            remove. This is not reversible.', 'warning');
      }
    }

    $form['msg-top'] = [
      '#type' => 'item',
      '#markup' => 'Chado is a relational database schema that underlies many
        GMOD installations. It is capable of representing many of the general
        classes of data frequently encountered in modern biology such as sequence,
        sequence comparisons, phenotypes, genotypes, ontologies, publications,
        and phylogeny. It has been designed to handle complex representations of
        biological knowledge and should be considered one of the most
        sophisticated relational schemas currently available in molecular
        biology.',
      '#prefix' => '<blockquote>',
      '#suffix' => t('- <a href="@url">GMOD Chado Documentation</a></blockquote>',
        ['@url' => Url::fromUri('https://chado.readthedocs.io/en/rtd/')->toString()]),
    ];

    // Now that we support multiple chado instances, we need to list all the
    // currently installed ones here since they may be different versions.
    // @upgrade currently we have no way to pull out all chado installs.
    $rows = [];
    $options = [];
    $installs = chado_get_installed_schemas();
    foreach($installs as $i) {
      $rows[$i->schema_name] = [
        $i->schema_name,
        $i->version,
        \Drupal::service('date.formatter')->format($i->created),
        \Drupal::service('date.formatter')->format($i->updated)
      ];
      $options[] = $i->schema_name;
    }
    if (!empty($rows)) {
      ksort($rows);
      $form['current_version'] = [
        '#type' => 'table',
        '#caption' => t('Installed version(s) of Chado'),
        '#header' => [t('Schema Name'), t('Chado Version'), t('Created'), t('Updated')],
        '#rows' => $rows,
      ];
      if ((CLONE_ACTION == $form_state->getValue('action_to_do'))
          || (DROP_ACTION == $form_state->getValue('action_to_do'))) {
        $form['current_version'] = array_merge(
          $form['current_version'],
          [
            '#type' => 'tableselect',
            '#options' => $rows,
            '#multiple' => FALSE,
            '#js_select' => FALSE,
            '#default_value' => array_key_first($rows),
          ]
        );
      }
    }
    else {
      $form['current_version'] = [
        '#type' => 'item',
        '#markup' => t('<div class="messages messages--warning">
            <h2>Chado Not Installed</h2>
            <p>Please select an action below and click "Submit". We recommend
            you choose the most recent version of Chado.</p>
          </div>'),
      ];
    }

    // Add a list of existing Chado instances that are not integrated to Tripal.
    $rows = [];
    $options = [];
    $available = chado_get_available_schemas();
    $not_installed_schemas = 0;
    foreach($available as $a) {
      if ((!array_key_exists($a['schema_name'], $installs))
          && (\Drupal\tripal_chado\api\ChadoSchema::TEST_SCHEMA_NAME != $a['schema_name'])) {
        $notes = '';
        if ($a['has_data']) {
          $notes .= t('Contains data (@size). ', ['@size' => format_size($a['size'])]);
        }
        if ($a['is_test']) {
          $notes .= t('Unit test database. ');
        }
        $rows[$a['schema_name']] = [
          $a['schema_name'],
          $a['version'],
          $notes,
        ];
        $options[] = $a['schema_name'];
        ++$not_installed_schemas;
      }
    }
    if (!empty($rows)) {
      ksort($rows);
      $form['available_chado'] = [
        '#type' => 'table',
        '#caption' => t('Other available Chado instance(s) not integrated in Tripal'),
        '#header' => [t('Schema Name'), t('Chado Version'), t('Notes'), ],
        '#rows' => $rows,
      ];
      if (IMPORT_ACTION == $form_state->getValue('action_to_do')) {
        $form['available_chado'] = array_merge($form['available_chado'], [
          '#type' => 'tableselect',
          '#options' => $rows,
          '#multiple' => FALSE,
          '#js_select' => FALSE,
          '#default_value' => array_key_first($rows),
        ]);
      }
    }

    $form['msg-middle'] = [
      '#type' => 'item',
      '#markup' => t('<br /><p>Use the following drop-down to choose whether you want
      to install or upgrade Chado. You can use the advanced options to change
      the schema name for multi-chado install.</p>'),
    ];

    $options = [
      INSTALL_13_ACTION => t('New Install of Chado v1.3 (erases all
        existing Chado data if this chado schema already exists).'),
    ];
    if ($not_installed_schemas) {
      $options[IMPORT_ACTION] = t('Integrate Existing Chado (integrate an
        existing Chado instance into Tripal)');
    }
    if ($installs) {
      $options[CLONE_ACTION] = t('Clone Existing Chado (clone existing data
        into a new instance)');
      $options[DROP_ACTION] = t('Remove Existing Chado (erases all existing
        chado data)');
    }
    $form['action_to_do'] = [
      '#type' => 'select',
      '#title' => 'Installation/Upgrade Action',
      '#options' => $options,
      '#required' => TRUE,
      "#empty_option" => t('- Select an action to perform -'),
      '#ajax' => [
        'callback' => '::ajaxFormVersionUpdate',
        'wrapper' => 'tripal_chado_load_form',
        'effect' => 'fade',
        'method' => 'replace',
        'disable-refocus' => FALSE,
      ],
    ];

    // Add some information to admin regarding chado installation.
    $info[] = t('Tripal Chado Integration now supports <strong>setting the schema
      name</strong> local Chado instances are installed in. In Tripal v3 and
      lower, the recommended name for your chado schema was <code>chado</code>
      and that is still the default. Note: Schema name cannot be changed once
      set.');
    $info[] = t('Additionally, you can now install <strong>multiple chado
    instances</strong>, although this is only recommended as needed. Examples
    where you may need multiple chado instances: (1) separate testing version of
    chado, (2) different chado instances for specific user groups (i.e. breeders
    of different crops), (3) both a public and private chado where Drupal
    permissions are not sufficient.');
    $info[] = t('To install multiple chado instances, submit this form once for
    each chado instance indicating a different schema name each time.
    <strong>Each chado instance must have a unique name.</strong>');
    if ((!$form_state->getValue('action_to_do'))
        || (INSTALL_13_ACTION == $form_state->getValue('action_to_do'))
        || (CLONE_ACTION == $form_state->getValue('action_to_do'))) {
      $form['advanced'] = [
        '#type' => 'details',
        '#title' => t('Advanced Options'),
        '#description' => '<p>' . implode ('</p><p>', $info) . '</p>',
      ];
      
      // Allow the admin to set the chado schema name.
      $form['advanced']['schema_name'] = [
        '#type' => 'textfield',
        '#title' => t('Chado Schema Name'),
        '#required' => TRUE,
        '#description' => t('The name of the schema to install chado in.'),
        '#default_value' => 'chado',
      ];
    }

    $form['button'] = [
      '#type' => 'submit',
      '#value' => t('Submit'),
    ];

    $form['#prefix'] = '<div id="tripal_chado_load_form">';
    $form['#suffix'] = '</div>';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // We do not want to allow re-installation of Chado if other
    // Tripal modules are installed.  This is because the install files
    // of those modules may add content to Chado and reinstalling Chado
    // removes that content which may break the modules.
    //
    // Cannot do this and still allow multiple chado installs...
    // @todo add a hook for modules to add in to the prepare or install processes.

    // Get schema name from the first used field.
    $schema_name =
      $values['schema_name']
      ?: $values['current_version']
      ?: $values['available_chado']
    ;
    $target_schema_name = $values['current_version'];
    if ((CLONE_ACTION == $form_state->getValue('action_to_do'))
      && (!$target_schema_name)) {
      $form_state->setErrorByName('current_version', t('You must select an existing source schema to clone.'));
    }
    // Check provided schema name.
    $schema_issue = \Drupal\tripal_chado\api\ChadoSchema::isInvalidSchemaName($schema_name);
    if ($schema_issue) {
      $form_state->setErrorByName('schema_name', $schema_issue);
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $action_to_do = $form_state->getValue('action_to_do');
    $schema_name =
      $form_state->getValue('schema_name')
      ?: $form_state->getValue('current_version')
      ?: $form_state->getValue('available_chado')
    ;
    $args = [$action_to_do];

    $current_user = \Drupal::currentUser();

    switch ($action_to_do) {
      case INSTALL_13_ACTION:
        $args = [$action_to_do, $schema_name];
        tripal_add_job($action_to_do, 'tripal_chado',
          'tripal_chado_install_chado', $args, $current_user->id(), 10);
        break;
      case IMPORT_ACTION:
        $args = [$schema_name];
        tripal_add_job($action_to_do, 'tripal_chado',
            'tripal_chado_import_schema', $args, $current_user->id(), 10);
        break;
      case CLONE_ACTION:
        $args = [$schema_name];
        tripal_add_job($action_to_do, 'tripal_chado',
            'tripal_chado_clone_schema', $args, $current_user->id(), 10);
        break;
      case DROP_ACTION:
        $args = [$schema_name];
        tripal_add_job($action_to_do, 'tripal_chado',
            'tripal_chado_drop_schema', $args, $current_user->id(), 10);
        break;
    }

  }

  /**
   * Ajax callback: triggered when version is selected
   * to provide additional feedback and help text.
   *
   * @param array $form
   * @param array $form_state
   * @return array
   *   Portion of the form to re-render.
   */
  public function ajaxFormVersionUpdate($form, $form_state) {
    return $form;
  }

}
