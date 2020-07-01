<?php

namespace Drupal\bos311;

use phpDocumentor\Reflection\Types\Integer;

class FetchResponses
{

    private array $services;
    private $numberOfRecordsToGetPerRun = 500;
    private $recordsSaved = 0;
    private $recordsFailedToSave = 0;
    private $apiRequestsMade = 0;
    private $serviceRequestId;
    private $rawRecord;

    private int $highestLocalServiceRequestId;
    private int $highestRemoteServiceRequestId;
    private int $lowestLocalServiceRequestId;
    private int $lowestRemoteServiceRequestId = 101000000000;


    public function __construct()
    {
        $this->findHighestRemoteServiceRequestId();
        $this->findHighestLocalServiceRequestId();
        $this->findLowestLocalServiceRequestId();
    }

    public function doFetchRecords()
    {
        $this->doFetchRecordsLi();
        $this->doFetchRecordsFi();
        $this->recordStatistics();
    }

    private function doFetchRecordsLi()
    {
        if (!$this->serviceRequestId) {
            $this->serviceRequestId = $this->highestLocalServiceRequestId + 1;
        }
        if ($this->serviceRequestId > $this->highestRemoteServiceRequestId) {
            // Reached the top of the list.
            $this->serviceRequestId = null;
            return;
        }
        if (($this->serviceRequestId - $this->highestLocalServiceRequestId) > $this->numberOfRecordsToGetPerRun) {
            // Reached the max number of records to get per run.
            $this->serviceRequestId = null;
            return;
        }

        $this->processRecord();

        $this->serviceRequestId = $this->serviceRequestId + 1;
        $this->doFetchRecordsLi();
    }

    private function doFetchRecordsFi() {
        if (!$this->serviceRequestId) {
            $this->serviceRequestId = $this->lowestLocalServiceRequestId - 1;
        }
        if ($this->serviceRequestId < $this->lowestRemoteServiceRequestId) {
            // Reached the end of the list.
            return;
        }
        if (($this->lowestLocalServiceRequestId - $this->serviceRequestId) > $this->numberOfRecordsToGetPerRun) {
            // Reached the max number of records to get per run. Save the serviceRequestId in state so we don't have
            // to go through serviceRequestIds that don't give a response more than once.
            // This will also allow us to bridge large gaps in sequential numbers (larger than the total number to get
            // per run) by allowing us to start on the lowest attempted next time rather than the lowest saved in the
            // database.
            \Drupal::state()->set('lowest-attempted-service-request-id', $this->serviceRequestId + 1);
            return;
        }
        $this->processRecord();

        $this->serviceRequestId = $this->serviceRequestId - 1;
        $this->doFetchRecordsFi();
    }

    protected function fetchRawRecord() {
        $rawRecord = Response::fetch("https://mayors24.cityofboston.gov/open311/v2/requests.json?service_request_id=$this->serviceRequestId");
        $this->apiRequestsMade++;
        $this->rawRecord = reset($rawRecord);
    }

    private function isValidRawRecord() {
        if (!$this->rawRecord) {
            return false;
        }
        return true;
    }

    private function saveRecord() {
        $record = new Record($this->rawRecord, $this->serviceRequestId);
        if ($newRecord = $record->saveRecord()) {
            $this->recordsSaved++;
            return $newRecord;
        }
        else {
            $this->recordsFailedToSave++;
            \Drupal::logger('Boston 311 Reports')->notice('Failed to save Request ID: ' . $this->serviceRequestId);
        }
    }

    private function findHighestRemoteServiceRequestId() {
        $response = Response::fetch("https://mayors24.cityofboston.gov/open311/v2/requests.json");
        $recordToUse = reset($response);

        foreach ($response as $record) {
            // If one of the first 50 responses is open, use that request ID as the starting point since it will be higher
            // than any recently closed ones. If not, fall back to the first record.
            if ($record->status == "open") {
                $recordToUse = $record;
                break;
            }
        }

        $highestRemoteServiceRequestId = $recordToUse->service_request_id;

        $this->highestRemoteServiceRequestId = $highestRemoteServiceRequestId;
    }

    private function findHighestLocalServiceRequestId() {
        $nids = \Drupal::entityQuery('node')
            ->condition('type','report')
            ->range(0, 1)
            ->sort('field_service_request_id', 'DESC')
            ->execute();

        if ($nids) {
            $node = \Drupal::entityTypeManager()->getStorage('node')->load(reset($nids));
            $highestLocalServiceRequestId = $node->field_service_request_id->value;
        }
        else {
            $highestLocalServiceRequestId = $this->highestRemoteServiceRequestId;
        }

         $this->highestLocalServiceRequestId = $highestLocalServiceRequestId;
    }

    private function findLowestLocalServiceRequestId() {
        $lowestAttemptedLocalServiceRequestId = \Drupal::state()->get('lowest-attempted-service-request-id', $this->highestRemoteServiceRequestId);

        $nids = \Drupal::entityQuery('node')
            ->condition('type','report')
            ->range(0, 1)
            ->sort('field_service_request_id', 'ASC')
            ->execute();

        if ($nids) {
            $node = \Drupal::entityTypeManager()->getStorage('node')->load(reset($nids));
            $lowestLocalServiceRequestId = $node->field_service_request_id->value;
        }
        else {
            $lowestLocalServiceRequestId = $this->highestRemoteServiceRequestId;
        }

        $lowestLocalServiceRequestId = min($lowestLocalServiceRequestId, $lowestAttemptedLocalServiceRequestId);

        $this->lowestLocalServiceRequestId = $lowestLocalServiceRequestId;
    }

    private function processRecord() {
        $this->fetchRawRecord();
        if ($this->isValidRawRecord()) {
            $this->saveRecord();
        }
    }

    private function recordStatistics() {
        $messageParts = [
            'API calls:' => $this->apiRequestsMade,
            'Records saved:' => $this->recordsSaved,
            'Records failed' => $this->recordsFailedToSave,
            'Start LI:' => $this->highestLocalServiceRequestId,
            'Start FI:' => $this->lowestLocalServiceRequestId,
        ];

        $message = '';

        foreach ($messageParts as $name => $stat) {
            $message = $message . "<li><strong>$name:</strong> $stat</li>";
        }
        $message = "<ul>$message</ul>";

        \Drupal::logger('Boston 311 Reports')->notice($message);
    }

}
