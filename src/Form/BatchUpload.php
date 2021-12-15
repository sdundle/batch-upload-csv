<?php

namespace Drupal\batch_upload\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\Messenger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * BulkUpload class.
 */
class BatchUpload extends FormBase {

  /**
   * The request object.
   *
   * @var object
   */
  public $request;

  /**
   * The message to display.
   *
   * @var string
   */
  public $messenger;

  /**
   * Class constructor.
   */
  public function __construct(RequestStack $request, Messenger $messenger) {
    $this->request = $request;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    return new static(
          // Load the service required to construct this class.
          $container->get('request_stack'),
          $container->get('messenger')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'batch_api_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['file_upload'] = [
      '#type' => 'file',
      '#title' => $this->t('Import CSV file'),
      '#description' => $this->t('The CSV file that needs to be imported.'),
      '#autoupload' => FALSE,
      '#upload_validators' => [
        'file_validate_extensions' => ['csv', 'CSV'],
        'file_validate_size' => 1000000,
      ],
      '#weight' => -4,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#button_type' => 'primary',
      '#prefix' => '<div class="form-actions js-form-wrapper form-wrapper">',
      '#suffix' => '</div>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $isUploaded = $this->getRequest()->files->get('files', []);
    $extensions = $form_state->getCompleteForm()['file_upload']['#upload_validators']['file_validate_extensions'];
    if (empty($isUploaded['file_upload'])) {
      $form_state->setErrorByName('file_upload', $this->t('Please select csv file to upload.'));
    }
    elseif (!empty($isUploaded['file_upload'])) {
      if (in_array($isUploaded['file_upload']->getClientOriginalExtension(), $extensions) === FALSE) {
        $form_state->setErrorByName('file_upload', $this->t('Please select csv extension to upload.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->handleFileData($this->request->getCurrentRequest()->files, $form, $form_state);
  }

  /**
   * To import csv file data.
   *
   * @param \Symfony\Component\HttpFoundation\FileBag $filedata
   *   Field data.
   * @param object $form
   *   Current form.
   * @param object $form_state
   *   Current form state.
   */
  public function handleFileData(FileBag $filedata, $form, $form_state) {
    $uploadedFiles = $filedata->get('files');
    $location = $uploadedFiles['file_upload']->getRealPath();
    if (($handle = fopen($location, 'r')) === FALSE) {
      return;
    }

    // Read the csv data.
    $headerData = [];
    $csvData = [];
    while (($data = fgetcsv($handle)) !== FALSE) {
      if (empty($headerData)) {
        $headerData = $data;
      }
      else {
        $csvData[] = $data;
      }
    }
    fclose($handle);

    // Ignore headers.
    $columnsToIgnore = [];
    foreach ($headerData as $column => $header) {
      if (!isset($fieldNames[$header])) {
        $columnsToIgnore[] = $column;
      }
    }

    // Save the csv data in fieldsData array.
    $fieldData = [];
    foreach ($csvData as $csvRow) {
      $row_data = [];
      foreach ($csvRow as $column => $value) {
        if (in_array($column, $columnsToIgnore)) {
          // continue;.
        }
        $row_data[$headerData[$column]] = Xss::filter(Html::escape(trim($value)));
      }
      $fieldData[] = $row_data;
    }
    $fieldData = array_filter($fieldData);

    // Add the csv data to batch processing.
    $this->batchProcessFields($fieldData, $form, $form_state);
  }

  /**
   * Batch callback: CSV import operation.
   *
   * @param array $fieldData
   *   Structured array of csv data. The keys are table column field names.
   * @param array $form
   *   The form.
   * @param array $form_state
   *   The current form state.
   */
  public function batchProcessFields(array $fieldData, $form, $form_state) {
    $operations = [];
    foreach ($fieldData as $data) {
      $data = ['full_name' => $data['first_name'] . ' ' . $data['last_name']];
      $operations[] = [
        '\Drupal\batch_upload\Form\BatchUpload::batchImport',
            [
              'fieldData' => $data,
            ],
      ];
    }
    // Batch array.
    $batch = [
      'title' => 'Importing CSV data',
      'init_message' => $this->t('Commencing'),
      'operations' => $operations,
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('An error occurred during processing'),
      'finished' => '\Drupal\batch_upload\Form\BatchUpload::batchFinished',
    ];
    // Set the batch.
    batch_set($batch);
  }

  /**
   * Batch import function to insert records to database.
   *
   * @param array $fieldData
   *   Structured array of csv data. The keys are table column field names.
   * @param array $context
   *   The batch api context.
   */
  public static function batchImport(array $fieldData, array &$context) {
    $i = 1;
    $database = \Drupal::database();
    $database->insert('batch_upload_csv')
      ->fields($fieldData)
      ->execute();
    $context['results'][] = $i;
  }

  /**
   * Batch callback: Finish bulk csv import process.
   *
   * @param bool $success
   *   Success or not.
   * @param array $results
   *   Results array.
   */
  public static function batchFinished($success, array $results) {
    $messenger = \Drupal::messenger();
    if ($success) {
      $messenger->addStatus(\Drupal::translation()
        ->formatPlural(count($results), '1 row imported successfully.', '@count rows imported successfully.'));
    }
    else {
      $messenger->addError(t('Finished with errors.'));
    }
  }

}
