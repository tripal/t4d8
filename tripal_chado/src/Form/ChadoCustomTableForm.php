<?php

namespace Drupal\tripal_chado\Form;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

class ChadoCustomTableForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chado_custom_table_form';
  }


  /**
   * A Form to Create/Edit a Custom table.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $table_id = null) {     
    
    if (!$table_id) {
      $action = 'Add';
    }
    else {
      $action = 'Edit';
    }
    
  
    // get this requested table
    $default_schema = '';
    $default_force_drop = 0;
    if (strcmp($action, 'Edit') == 0) {
      $sql = "SELECT * FROM tripal_custom_tables WHERE table_id = :table_id ";
      $results = chado_query($sql, [':table_id' => $table_id]);
      $custom_table = $results->fetchObject();
  
      // if this is a materialized view then don't allow editing with this function
      if (property_exists($custom_table, 'mview_id') and $custom_table->mview_id) {
        drupal_set_message("This custom table is a materialized view. Please use the " . Link::fromTextAndUrl('Materialized View', Url::fromUserInput('/admin/tripal/storage/chado/mviews')) . " interface to edit it.", 'error');
        drupal_goto("admin/tripal/storage/chado/custom_tables");
        return [];
      }
  
      // set the default values.  If there is a value set in the
      // form_state then let's use that, otherwise, we'll pull
      // the values from the database
      if ($form_state->getValue('schema')) {
        $default_schema = $form_state->getValue('schema');
      }
      if ($form_state->getValue('force_drop')) {
        $default_force_drop = $form_state->getValue('force_drop');
      }
  
      if (!$default_schema) {
        $default_schema = var_export(unserialize($custom_table->schema), 1);
        $default_schema = preg_replace('/=>\s+\n\s+array/', '=> array', $default_schema);
      }
    }
  
    
    // Build the form
    $form['action'] = [
      '#type' => 'value',
      '#value' => $action,
    ];
  
    $form['table_id'] = [
      '#type' => 'value',
      '#value' => $table_id,
    ];
  
    $form['instructions'] = [
      '#type' => 'details',
      '#title' => 'Instructions',
      '#open' => False,
    ];
    
    $form['instructions']['text'] = [
      '#type' => 'item',
      '#markup' => '<p>' . t('At times it is necessary to add a custom table
         to the Chado schema. These are not offically sanctioned tables but may
         be necessary for local data requirements. Avoid creating custom tables
         when possible as other GMOD tools may not recognize these tables nor
         the data in them.  Linker tables or property tables are often a good
         candidate for a custom table. For example a table to link stocks and
         libraries (e.g. library_stock). Try to model linker or propery tables
         after existing tables.  If the table already exists it will not be
         modified.  To force dropping and recreation of the table
         click the checkbox below.  Tables are defined using the ' .
         Link::fromTextAndUrl('Drupal Schema API', Url::fromUri('https://api.drupal.org/api/drupal/includes!database!schema.inc/group/schemaapi/7',
            ['attributes' => ['target' => '_blank']]))->toString() . '</p>' .
          '<p>Please note that table names should be all lower-case.</p>'
        ),
    ];
        

    $form['instructions']['example'] = [
      '#type' => 'item',
      '#markup' => "Example library_stock table: <pre>[
  'table' => 'library_stock',
  'fields' => [
    'library_stock_id' => [
      'type' => 'serial',
      'not null' => TRUE,
    ],
    'library_id' => [
      'type' => 'int',
      'not null' => TRUE,
    ],
    'stock_id' => [
      'type' => 'int',
      'not null' => TRUE,
    ]
  ],
  'primary key' => [
    'library_stock_id'
  ],
  'unique keys' => [
    'library_stock_c1' => [
      'library_id',
      'stock_id'
    ]
  ],
  'foreign keys' => [
    'library' => [
      'table' => 'library',
      'columns' => [
        'library_id' => 'library_id'
      ],
    ],
    'stock' => [
      'table' => 'stock',
      'columns' => [
        'stock_id' => 'stock_id'
      ]
    ]
  ]
]</pre>",
    ];
  
    if ($action == 'Add') {
      $form['force_drop'] = [
        '#type' => 'value',
        '#value' => $default_force_drop,
      ];
    }
    else {
      $form['force_drop'] = [
        '#type' => 'checkbox',
        '#title' => t('Re-create table'),
        '#description' => t('Check this box if your table already exists and you would like to drop it and recreate it.'),
        '#default_value' => $default_force_drop,
      ];
    }
    $form['schema'] = [
      '#type' => 'textarea',
      '#title' => t('Schema Array'),
      '#description' => t('Please enter the ' . Link::fromTextAndUrl('Drupal Schema API', Url::fromUri('https://api.drupal.org/api/drupal/includes!database!schema.inc/group/schemaapi/7', ['attributes' => ['target' => '_blank']]))->toString() . ' compatible array that defines the table.'),
      '#required' => FALSE,
      '#default_value' => $default_schema,
      '#rows' => 25,
    ];
  
    if ($action == 'Edit') {
      $value = 'Save';
    }
    if ($action == 'Add') {
      $value = 'Add';
    }
  
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t($value),
      '#executes_submit_callback' => TRUE,
    ];
    
    $form['cancel'] = [
      '#type' => 'markup',
      '#markup' => Link::fromTextAndUrl('Cancel', Url::fromUserInput('/admin/tripal/storage/chado/custom_tables'))->toString(),
    ];
  
    
    return $form;
  }
  
  /**
   * Validate the Create/Edit custom table form.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) { 
    $values = $form_state->getValues();   
    $action = $values['action'];
    $table_id = $values['table_id'];
    $schema = $values['schema'];
    $force_drop = $values['force_drop'];
  
    if (!$schema) {
      $form_state->setErrorByName($values['schema'], t('Schema array field is required.'));
    }
  
    // Make sure the array is valid.
    $schema_array = [];
    if ($schema) {
      $success = true;
      if (!$success) {
        $form_state->setErrorByName($values['schema'],
          t("The schema array should begin with the word 'array'."));
      }
      else {
        $success = eval("\$schema_array = $schema;");
        if ($success === FALSE) {
          $error = error_get_last();
          $form_state->setErrorByName('schema', t("The schema array is improperly formatted. Parse Error : " . $error["message"]));
        }
        if (is_array($schema_array) and !array_key_exists('table', $schema_array)) {
          $form_state->setErrorByName('schema', t("The schema array must have key named 'table'"));
        }
  
        // Validate the contents of the array.
        $error = chado_validate_custom_table_schema($schema_array);
        if ($error) {
          $form_state->setErrorByName('schema', $error);
        }
  
        if ($action == 'Edit') {
          // See if the table name has changed. If so, then check to make sure
          // it doesn't already exists. We don't want to drop a table we 
          // didn't mean to.
          $sql = "SELECT * FROM tripal_custom_tables WHERE table_id = :table_id";
          $results = chado_query($sql, [':table_id' => $table_id]);
          $ct = $results->fetchObject();
          if ($ct->table_name != $schema_array['table']) {
            $exists = chado_table_exists($schema_array['table']);
            if ($exists) {
              $form_state->setErrorByName($values['schema'],
                t("The table name already exists, please choose a different name."));
            }
          }
        }
      }
    }
  }
  
  /**
   * Submit the Create/Edit Custom table form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $action = $values['action'];
    $table_id = $values['table_id'];
    $schema = $values['schema'];
    $force_drop = $values['force_drop'];
  
    $skip_creation = 1;
    if ($force_drop) {
      $skip_creation = 0;
    }
  
    // convert the schema into a PHP array
    $schema_arr = [];
    eval("\$schema_arr = $schema;");
  
  
    if (strcmp($action, 'Edit') == 0) {
      $action_result = chado_edit_custom_table($table_id, $schema_arr['table'], $schema_arr, $skip_creation);
      if($action_result) {
        \Drupal::messenger()->addMessage(t("The custom table was succesfully updated."), 'status');
      }
      else {
        $link = Link::fromTextAndUrl(t('recent logs'), Url::fromUserInput('/admin/reports/dblog'))->toString();
        \Drupal::messenger()->addError(t("Could not update the custom table. Please see the @logs for further details.",
            ['@logs' => $link]), 'status');
      }
    }
    elseif (strcmp($action, 'Add') == 0) {
      $action_result = chado_create_custom_table($schema_arr['table'], $schema_arr, $skip_creation, NULL, FALSE);
      if($action_result) {
        \Drupal::messenger()->addMessage(t("Custom table has been added."), 'status');
      }
      else {
        \Drupal::messenger()->addError(t("Custom table could not be created. Please see logs for further details."), 'status');
      }
    }
    else {
      drupal_set_message(t("No action performed."));
    }  
    $response = new RedirectResponse(\Drupal\Core\Url::fromUserInput('/admin/tripal/storage/chado/custom_tables')->toString());
    $response->send();    
  }
  
}




?>