<?php
/**
 * @file
 * Contains \Drupal\tripal\Form\TripalImporterForm.
 */
namespace Drupal\tripal\Form;

use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Provides a test form object.
 */
class TripalImporterForm implements FormInterface {
  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'tripal_admin_form_tripalimporter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $class = NULL) {
    $user = \Drupal::currentUser();

    // Let us do a basic check to make sure CHADO is installed since we need it
    // for even the most basic form generation - particularly for analysis
    $sql = "SELECT * FROM {analysis} ORDER BY name";
    $hasChado = true;
    try {
      chado_query($sql);
    }
    catch(\Exception $ex) {
      $hasChado = false;
    }

    if($hasChado) {
      // Load the specific importer from the class
      tripal_load_include_importer_class($class);

      $form['importer_class'] = [
        '#type' => 'value',
        '#value' => $class,
      ];

      if ((array_key_exists('file_upload', $class::$methods) and $class::$methods['file_upload'] == TRUE) or
          (array_key_exists('file_local', $class::$methods) and $class::$methods['file_local'] == TRUE) or
          (array_key_exists('file_remote', $class::$methods) and $class::$methods['file_remote'] == TRUE)) {
        $form['file'] = [
        '#type' => 'fieldset',
        '#title' => t($class::$upload_title),
        '#description' => t($class::$upload_description),
        '#weight' => -15,
        ];
      }

      if (array_key_exists('file_upload', $class::$methods) and $class::$methods['file_upload'] == TRUE) {
        // $existing_files = tripal_get_user_uploads($user->uid, $class::$file_types);
        $existing_files = tripal_get_user_uploads($user->id(), $class::$file_types);
        if (count($existing_files) > 0) {
          $fids = [0 => '--Select a file--'];
          foreach ($existing_files as $fid => $file) {
            //$fids[$fid] = $file->filename . ' (' . tripal_format_bytes($file->filesize) . ') '; // old
            $fids[$fid] = $file->getFilename() . ' (' . tripal_format_bytes($file->filesize) . ') ';
          }
          $form['file']['file_upload_existing'] = [
            '#type' => 'select',
            '#title' => t('Existing Files'),
            '#description' => t('You may select a file that is already uploaded.'),
            '#options' => $fids,
          ];
        }
        $form['file']['file_upload'] = [
          '#type' => 'html5_file',
          '#title' => '',
          '#description' => 'Remember to click the "Upload" button below to send ' .
              'your file to the server.  This interface is capable of uploading very ' .
              'large files.  If you are disconnected you can return, reload the file and it ' .
              'will resume where it left off.  Once the file is uploaded the "Upload ' .
              'Progress" will indicate "Complete".  If the file is already present on the server ' .
              'then the status will quickly update to "Complete".',
          '#usage_type' => 'tripal_importer',
          '#usage_id' => 0,
          '#allowed_types' => $class::$file_types,
          '#cardinality' => $class::$cardinality,
        ];
      }

      if (array_key_exists('file_local', $class::$methods) and $class::$methods['file_local'] == TRUE) {
        $form['file']['file_local'] = [
          '#title' => t('Server path'),
          '#type' => 'textfield',
          '#maxlength' => 5120,
          '#description' => t('If the file is local to the Tripal server please provide the full path here.'),
        ];
      }
      if (array_key_exists('file_remote', $class::$methods) and $class::$methods['file_remote'] == TRUE) {
          $form['file']['file_remote'] = [
            '#title' => t('Remote path'),
            '#type' => 'textfield',
            '#maxlength' => 5102,
            '#description' => t('If the file is available via a remote URL please provide the full URL here.  The file will be downloaded when the importer job is executed.'),
          ];
      }

      if ($class::$use_analysis) {
        // get the list of analyses
        $sql = "SELECT * FROM {analysis} ORDER BY name";
        $org_rset = chado_query($sql);
        $analyses = [];
        $analyses[''] = '';
        while ($analysis = $org_rset->fetchObject()) {
          $analyses[$analysis->analysis_id] = "$analysis->name ($analysis->program $analysis->programversion, $analysis->sourcename)";
        }
        $form['analysis_id'] = [
          '#title' => t('Analysis'),
          '#type' => t('select'),
          '#description' => t('Choose the analysis to which the uploaded data will be associated. ' .
              'Why specify an analysis for a data load?  All data comes from some place, even if ' .
              'downloaded from a website. By specifying analysis details for all data imports it ' .
              'provides provenance and helps end user to reproduce the data set if needed. At ' .
              'a minimum it indicates the source of the data.'),
          '#required' => $class::$require_analysis,
          '#options' => $analyses,
          '#weight' => -14,
        ];
      }

      // Retrieve the forms from the custom TripalImporter class
      // for this loader.
      $importer = new $class();
      $element = [];
      $element_form = $importer->form($element, $form_state);
      // Quick check to make sure we had an array returned so array_merge() works.
      if (!is_array($element_form)) {
        $element_form = array();
      }

      // Merge the custom form with our default one.
      // This way, the custom TripalImporter can use the #weight property
      // to change the order of their elements in reference to the default ones.
      $form = array_merge($form, $element_form);

      $form['button'] = [
        '#type' => 'submit',
        '#value' => t($class::$button_text),
        '#weight' => 10,
      ];
      return $form;
    }    
    else {
      global $base_url;
      $form['error'] = array(
        '#markup' => '<p>We could not detect CHADO. You must install it
          in order to perform imports.<br /> 
          <h3>CHADO Installation Instructions</h3>
          You can install CHADO by visiting
          <a href="'. $base_url .'/admin/tripal/storage/chado/install">here</a>.
          <br /> 
          This will create a job which you can then execute by visiting
           <a href="' . $base_url . '/admin/tripal/tripal_jobs">here</a> and 
           ensuring you select <b>Execute</b> under the <b>Actions</b> dropdown 
           element for the Chado install job.<br />
          </p>'
      );
      return $form;
    }
  }  

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {


    // TODO: Convert the below D7 code to D8/D9
    global $user;

    //$run_args = $form_state['values'];
    $form_values = $form_state->getValues();
    $run_args = $form_values;
    $class = $form_values['importer_class'];
    tripal_load_include_importer_class($class);

    // Remove the file_local and file_upload args. We'll add in a new
    // full file path and the fid instead.
    unset($run_args['file_local']);
    unset($run_args['file_upload']);
    unset($run_args['file_upload_existing']);
    unset($run_args['form_build_id']);
    unset($run_args['form_token']);
    unset($run_args['form_id']);
    unset($run_args['op']);
    unset($run_args['button']);

    $file_local = NULL;
    $file_upload = NULL;
    $file_remote = NULL;
    $file_existing = NULL;

    // Get the form values for the file.
    if (array_key_exists('file_local', $class::$methods) and $class::$methods['file_local'] == TRUE) {
      $file_local = trim($form_values['file_local']);
    }
    if (array_key_exists('file_upload', $class::$methods) and $class::$methods['file_upload'] == TRUE) {
      $file_upload = trim($form_values['file_upload']);
      if (array_key_exists('file_upload_existing', $form_values) and $form_values['file_upload_existing']) {
        $file_existing = trim($form_values['file_upload_existing']);
      }
    }
    if (array_key_exists('file_remote', $class::$methods) and $class::$methods['file_remote'] == TRUE) {
      $file_remote = trim($form_values['file_remote']);
    }

    // Sumbit a job for this loader.
    $fname = '';
    $fid = NULL;
    $file_details = [];
    if ($file_existing) {
        $file_details['fid'] = $file_existing;
    }
    elseif ($file_local) {
      $fname = preg_replace("/.*\/(.*)/", "$1", $file_local);
      $file_details['file_local'] = $file_local;
    }
    elseif ($file_upload) {
      $file_details['fid'] = $file_upload;
    }
    elseif ($file_remote) {
      $file_details['file_remote'] = $file_remote;
    }
    try {
      // Now allow the loader to do its own submit if needed.
      $importer = new $class();
      $importer->formSubmit($form, $form_state);
      // If the formSubmit made changes to the $form_state we need to update the
      // $run_args info.
      if ($run_args !== $form_values) {
        $run_args = $form_values;
      }

      // If the importer wants to rebuild the form for some reason then let's
      // not add a job.
      if ($form_state->isRebuilding() == TRUE) {
        return;
      }

      $importer->create($run_args, $file_details);
      $importer->submitJob();

    } catch (Exception $e) {
        drupal_set_message('Cannot submit import: ' . $e->getMessage(), 'error');
    }  
  }

    /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Convert the validation code into the D8/9 equivalent
    $form_values = $form_state->getValues();
    $class = $form_values['importer_class'];
    var_dump($class);
    tripal_load_include_importer_class($class);

    $file_local = NULL;
    $file_upload = NULL;
    $file_remote = NULL;
    $file_existing = NULL;

    // Get the form values for the file.
    if (array_key_exists('file_local', $class::$methods) and $class::$methods['file_local'] == TRUE) {
      $file_local = trim($form_values['file_local']);
      // If the file is local make sure it exists on the local filesystem.
      if ($file_local) {
        // check to see if the file is located local to Drupal
        $file_local = trim($file_local);
        $dfile = $_SERVER['DOCUMENT_ROOT'] . base_path() . $file_local;
        if (!file_exists($dfile)) {
          // if not local to Drupal, the file must be someplace else, just use
          // the full path provided
          $dfile = $file_local;
        }
        if (!file_exists($dfile)) {
          // form_set_error('file_local', t("Cannot find the file on the system. Check that the file exists or that the web server has permissions to read the file."));
          $form_state->setErrorByName('file_local', t("Cannot find the file on the system. Check that the file exists or that the web server has permissions to read the file."));
        }
      }
    }
    if (array_key_exists('file_upload', $class::$methods) and $class::$methods['file_upload'] == TRUE) {
      $file_upload = trim($form_values['file_upload']);
      if (array_key_exists('file_upload_existing', $form_values) and $form_values['file_upload_existing']) {
        $file_existing = $form_values['file_upload_existing'];
      }
    }
    if (array_key_exists('file_remote', $class::$methods) and $class::$methods['file_remote'] == TRUE) {
      $file_remote = trim($form_values['file_remote']);
    }

    // The user must provide at least an uploaded file or a local file path.
    if ($class::$file_required == TRUE and !$file_upload and !$file_local and !$file_remote and !$file_existing) {
      $form_state->setErrorByName('file_local', t("You must provide a file."));
    }

    // Now allow the loader to do validation of it's form additions.
    $importer = new $class();
    $importer->formValidate($form, $form_state);    


  }

  /**
   * Build the form for a TripalImporter implementation.
   */
  function tripal_get_importer_form($form, &$form_state, $class) {
    global $user;

    tripal_load_include_importer_class($class);

    $form['importer_class'] = [
      '#type' => 'value',
      '#value' => $class,
    ];

    if ((array_key_exists('file_upload', $class::$methods) and $class::$methods['file_upload'] == TRUE) or
        (array_key_exists('file_local', $class::$methods) and $class::$methods['file_local'] == TRUE) or
        (array_key_exists('file_remote', $class::$methods) and $class::$methods['file_remote'] == TRUE)) {
      $form['file'] = [
      '#type' => 'fieldset',
      '#title' => t($class::$upload_title),
      '#description' => t($class::$upload_description),
      '#weight' => -15,
      ];
    }

    if (array_key_exists('file_upload', $class::$methods) and $class::$methods['file_upload'] == TRUE) {
      $existing_files = tripal_get_user_uploads($user->uid, $class::$file_types);
      if (count($existing_files) > 0) {
        $fids = [0 => '--Select a file--'];
        foreach ($existing_files as $fid => $file) {
          $fids[$fid] = $file->filename . ' (' . tripal_format_bytes($file->filesize) . ') ';
        }
        $form['file']['file_upload_existing'] = [
          '#type' => 'select',
          '#title' => t('Existing Files'),
          '#description' => t('You may select a file that is already uploaded.'),
          '#options' => $fids,
        ];
      }
      $form['file']['file_upload'] = [
        '#type' => 'html5_file',
        '#title' => '',
        '#description' => 'Remember to click the "Upload" button below to send ' .
            'your file to the server.  This interface is capable of uploading very ' .
            'large files.  If you are disconnected you can return, reload the file and it ' .
            'will resume where it left off.  Once the file is uploaded the "Upload ' .
            'Progress" will indicate "Complete".  If the file is already present on the server ' .
            'then the status will quickly update to "Complete".',
        '#usage_type' => 'tripal_importer',
        '#usage_id' => 0,
        '#allowed_types' => $class::$file_types,
        '#cardinality' => $class::$cardinality,
      ];
    }

    if (array_key_exists('file_local', $class::$methods) and $class::$methods['file_local'] == TRUE) {
      $form['file']['file_local'] = [
        '#title' => t('Server path'),
        '#type' => 'textfield',
        '#maxlength' => 5120,
        '#description' => t('If the file is local to the Tripal server please provide the full path here.'),
      ];
    }
    if (array_key_exists('file_remote', $class::$methods) and $class::$methods['file_remote'] == TRUE) {
        $form['file']['file_remote'] = [
          '#title' => t('Remote path'),
          '#type' => 'textfield',
          '#maxlength' => 5102,
          '#description' => t('If the file is available via a remote URL please provide the full URL here.  The file will be downloaded when the importer job is executed.'),
        ];
    }

    if ($class::$use_analysis) {
      // get the list of analyses
      $sql = "SELECT * FROM {analysis} ORDER BY name";
      $org_rset = chado_query($sql);
      $analyses = [];
      $analyses[''] = '';
      while ($analysis = $org_rset->fetchObject()) {
        $analyses[$analysis->analysis_id] = "$analysis->name ($analysis->program $analysis->programversion, $analysis->sourcename)";
      }
      $form['analysis_id'] = [
        '#title' => t('Analysis'),
        '#type' => t('select'),
        '#description' => t('Choose the analysis to which the uploaded data will be associated. ' .
            'Why specify an analysis for a data load?  All data comes from some place, even if ' .
            'downloaded from a website. By specifying analysis details for all data imports it ' .
            'provides provenance and helps end user to reproduce the data set if needed. At ' .
            'a minimum it indicates the source of the data.'),
        '#required' => $class::$require_analysis,
        '#options' => $analyses,
        '#weight' => -14,
      ];
    }

    // Retrieve the forms from the custom TripalImporter class
    // for this loader.
    $importer = new $class();
    $element = [];
    $element_form = $importer->form($element, $form_state);
    // Quick check to make sure we had an array returned so array_merge() works.
    if (!is_array($element_form)) {
      $element_form = arry();
    }

    // Merge the custom form with our default one.
    // This way, the custom TripalImporter can use the #weight property
    // to change the order of their elements in reference to the default ones.
    $form = array_merge($form, $element_form);

    $form['button'] = [
      '#type' => 'submit',
      '#value' => t($class::$button_text),
      '#weight' => 10,
    ];
    return $form;
  }

  /**
   * Validate function for the tripal_get_importer_form form().
   */
  function tripal_get_importer_form_validate($form, &$form_state) {

    $class = $form_state['values']['importer_class'];
    tripal_load_include_importer_class($class);

    $file_local = NULL;
    $file_upload = NULL;
    $file_remote = NULL;
    $file_existing = NULL;

    // Get the form values for the file.
    if (array_key_exists('file_local', $class::$methods) and $class::$methods['file_local'] == TRUE) {
      $file_local = trim($form_state['values']['file_local']);
      // If the file is local make sure it exists on the local filesystem.
      if ($file_local) {
        // check to see if the file is located local to Drupal
        $file_local = trim($file_local);
        $dfile = $_SERVER['DOCUMENT_ROOT'] . base_path() . $file_local;
        if (!file_exists($dfile)) {
          // if not local to Drupal, the file must be someplace else, just use
          // the full path provided
          $dfile = $file_local;
        }
        if (!file_exists($dfile)) {
          form_set_error('file_local', t("Cannot find the file on the system. Check that the file exists or that the web server has permissions to read the file."));
        }
      }
    }
    if (array_key_exists('file_upload', $class::$methods) and $class::$methods['file_upload'] == TRUE) {
      $file_upload = trim($form_state['values']['file_upload']);
      if (array_key_exists('file_upload_existing', $form_state['values']) and $form_state['values']['file_upload_existing']) {
        $file_existing = $form_state['values']['file_upload_existing'];
      }
    }
    if (array_key_exists('file_remote', $class::$methods) and $class::$methods['file_remote'] == TRUE) {
      $file_remote = trim($form_state['values']['file_remote']);
    }

    // The user must provide at least an uploaded file or a local file path.
    if ($class::$file_required == TRUE and !$file_upload and !$file_local and !$file_remote and !$file_existing) {
      form_set_error('file_local', t("You must provide a file."));
    }

    // Now allow the loader to do validation of it's form additions.
    $importer = new $class();
    $importer->formValidate($form, $form_state);
  }

  /**
   * Submit function for the tripal_get_importer_form form().
   */
  function tripal_get_importer_form_submit($form, &$form_state) {
    global $user;

    $run_args = $form_state['values'];
    $class = $form_state['values']['importer_class'];
    tripal_load_include_importer_class($class);

    // Remove the file_local and file_upload args. We'll add in a new
    // full file path and the fid instead.
    unset($run_args['file_local']);
    unset($run_args['file_upload']);
    unset($run_args['file_upload_existing']);
    unset($run_args['form_build_id']);
    unset($run_args['form_token']);
    unset($run_args['form_id']);
    unset($run_args['op']);
    unset($run_args['button']);

    $file_local = NULL;
    $file_upload = NULL;
    $file_remote = NULL;
    $file_existing = NULL;

    // Get the form values for the file.
    if (array_key_exists('file_local', $class::$methods) and $class::$methods['file_local'] == TRUE) {
      $file_local = trim($form_state['values']['file_local']);
    }
    if (array_key_exists('file_upload', $class::$methods) and $class::$methods['file_upload'] == TRUE) {
      $file_upload = trim($form_state['values']['file_upload']);
      if (array_key_exists('file_upload_existing', $form_state['values']) and $form_state['values']['file_upload_existing']) {
        $file_existing = trim($form_state['values']['file_upload_existing']);
      }
    }
    if (array_key_exists('file_remote', $class::$methods) and $class::$methods['file_remote'] == TRUE) {
      $file_remote = trim($form_state['values']['file_remote']);
    }

    // Sumbit a job for this loader.
    $fname = '';
    $fid = NULL;
    $file_details = [];
    if ($file_existing) {
        $file_details['fid'] = $file_existing;
    }
    elseif ($file_local) {
      $fname = preg_replace("/.*\/(.*)/", "$1", $file_local);
      $file_details['file_local'] = $file_local;
    }
    elseif ($file_upload) {
      $file_details['fid'] = $file_upload;
    }
    elseif ($file_remote) {
      $file_details['file_remote'] = $file_remote;
    }
    try {
      // Now allow the loader to do its own submit if needed.
      $importer = new $class();
      $importer->formSubmit($form, $form_state);
      // If the formSubmit made changes to the $form_state we need to update the
      // $run_args info.
      if ($run_args !== $form_state['values']) {
      $run_args = $form_state['values'];
      }

      // If the importer wants to rebuild the form for some reason then let's
      // not add a job.
      if (array_key_exists('rebuild', $form_state) and $form_state['rebuild'] == TRUE) {
        return;
      }

      $importer->create($run_args, $file_details);
      $importer->submitJob();

    } catch (Exception $e) {
        drupal_set_message('Cannot submit import: ' . $e->getMessage(), 'error');
    }
  }
}
