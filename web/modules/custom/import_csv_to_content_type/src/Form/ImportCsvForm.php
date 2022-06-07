<?php
namespace Drupal\import_csv_to_content_type\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Entity\File;
Use \Drupal\Core\File\FileSystemInterface;
class ImportCsvForm extends FormBase {
    protected $messenger;

    protected $entity_type_manager;

    public function __construct ( MessengerInterface $messenger, EntityTypeManagerInterface $entity_type_manager) {
      $this->entityTypeManager = $entity_type_manager;
      $this->messenger = $messenger;
    }

    public static function create(ContainerInterface $container) {
      return new static (
        $container->get('messenger'),
        $container->get('entity_type.manager')
      );

    }
    /**
     * Form ID
     */
    public function getFormId() {
        return 'custom_csv_import_form';
    }

    /**
     * build form to add custom fields to form
     */
    public function buildForm(array $form, FormStateInterface $form_state) {
  
      $form = array(
        '#attributes' => array('enctype' => 'multipart/form-data'),
      );
      
      $form['file_upload_details'] = array(
        '#markup' => '<b>'.t('The File'). '<b>',
      );
      
      $validators = array(
        'file_validate_extensions' => array('csv'),
      );

      $form['csv_file'] = array(
        '#type' => 'managed_file',
        '#name' => 'csv_file',
        '#title' => t('Upload File *'),
        '#size' => 20,
        '#description' => t('CSV format only'),
        '#upload_validators' => $validators,
        '#upload_location' => 'public://content/excel_files/',
      );
      
      $form['actions']['#type'] = 'actions';

      $form['actions']['submit'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Save'),
        '#button_type' => 'primary',
      );
  
      return $form;
    }
      /**
       * validate handler
       */
      public function validateForm(array & $form, FormStateInterface $form_state) {
        if ($form_state->getValue('csv_file') == NULL) {
           $form_state->setErrorByName('csv_file', $this->t('Upload a file'));
        }
      }

      /**
       * Submit handler
       */

      public function submitForm( array & $form, FormStateInterface $form_state) {
        $file = $this->entityTypeManager->getStorage('file')
                    ->load($form_state->getValue('csv_file')[0]);    
        $full_path = $file->get('uri')->value;
        $file_name = basename($full_path);

        $inputFileName = \Drupal::service('file_system')
            ->realpath('public://content/excel_files/'.$file_name);
		
        $spreadsheet = IOFactory::load($inputFileName);
        $sheetData = $spreadsheet->getActiveSheet();
        $rows = array();
        foreach ($sheetData->getRowIterator() as $row) {
          $cellIterator = $row->getCellIterator();
          $cellIterator->setIterateOnlyExistingCells(FALSE); 
          $cells = [];
          foreach ($cellIterator as $cell) {
            $cells[] = $cell->getValue();
          }
          $rows[] = $cells;
        }
        $header = $rows[0];
        array_shift($rows); // shift the first row containing filed names from the csv file
        foreach($rows as $row) {
          $values = $this->entityTypeManager->getStorage('node')->getQuery()->condition('title', $row[11])->execute();
          $node_not_exists = empty($values);
          if($node_not_exists) {
            /*if node does not exist create new node*/
            $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
            $query->condition('vid', "company");
            $query->condition('name', $row[6]);
            $company_tids = $query->execute();
            if(empty($company_tids)) {
              if(!(is_null($row[6]))) {
                $term = \Drupal\taxonomy\Entity\Term::create([
                    'vid' => 'company',
                    'name' => $row[6],
                ]);
                $term->save();
                $ctid = $term->id();
              }
            } 
            else {
              $company_tids = array_values($company_tids);
              $ctid = $company_tids[0];
            }
            $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
            $query->condition('vid', "position");
            $query->condition('name', $row[7]);
            $position_tids = $query->execute();
            if(empty($position_tids)) {
              if(!(is_null($row[7]))) {
                $term = \Drupal\taxonomy\Entity\Term::create([
                    'vid' => 'position',
                    'name' => $row[7],
                    $header[8] => $row[8],
                    $header[9] => $row[9],
                ]);
                $term->save();
                $ptid = $term->id();
              }
            } 
            else {
              $position_tids = array_values($position_tids);
              $ptid = $position_tids[0];
            }

            $uri = 'public://'.$row[12];
            /** create a image and save */
            $file = file_get_contents($row[12]);
            $file = file_save_data($file, 'public://'.$row[12], FileSystemInterface::EXISTS_REPLACE);
            $node = $this->entityTypeManager->getStorage('node')->create([
              'type'       => 'speaker',
              'title'      => $row[11],
              $header[0]  => $rows[0],
              $header[1]  => $row[1],
              $header[2]  => $row[2],
              $header[3]  => $row[3],
              $header[4]  => $row[4],
              $header[5]  => $row[5],
              $header[6]  => isset($ctid) ? ['target_id' => $ctid ]: '',
              $header[7]  => isset($ptid) ? ['target_id' => $ptid ]: '',
              $header[10]  => $row[10],
              $header[12] => [
                'target_id' => $file->id(),
                'alt' => 'Alt text',
                'title' => 'Title',
              ],
              $header[13]  => $row[13],
              $header[14]  => $row[14],
            ]);
            $node->save();
          }
        }
        $this->messenger->addMessage('Data imported successfully');
    }
}