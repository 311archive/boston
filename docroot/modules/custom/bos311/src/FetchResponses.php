<?php

namespace Drupal\bos311;

use Drupal\node\Entity\Node;

class FetchResponses
{

    private $timestart;

    private int $numberOfRecordsToGetPerRun = 20;
    private int $numberOfExistingOpenRecordsToUpdate = 19;

    private int $recordsSaved = 0;
    private int $recordsFailedToSave = 0;
    private int $apiRequestsMade = 0;
    private int $openRecentRecordsClosed = 0;
    private int $openOlderRecordsClosed = 0;
    private int $openLast50RecordsClosed = 0;

    private $serviceRequestId;
    private $rawRecord;
    private $apiKey;

    private int $highestLocalServiceRequestId;
    private int $highestRemoteServiceRequestId;
    private int $lowestLocalServiceRequestId;
    private int $lowestRemoteServiceRequestId = 101000000000;
    private array $openRecordsRecentNids;
    private array $openRecordsOlderNids;

    public function __construct()
    {
        $this->timestart = time();
        $this->setApiKey();
        $this->findHighestRemoteServiceRequestId();
        $this->findHighestLocalServiceRequestId();
        $this->findLowestLocalServiceRequestId();
    }

    public function doFetchRecords()
    {
        $this->doUpdateExistingOpenRecords();
        $this->doFetchRecordsLi();
        //$this->doFetchRecordsFi();
        $this->doProcessRecentUpdates();
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
            \Drupal::state()->set('highest-attempted-service-request-id', ($this->serviceRequestId - 1));
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

    private function doProcessRecentUpdates() {
        $recentReports = Response::fetch("https://mayors24.cityofboston.gov/open311/v2/requests.json?api_key=$this->apiKey");
        foreach ($recentReports as $rawRecord) {
            if ($rawRecord->status == 'closed') {
                $existingReport = $this->findRecordByServiceRequestId($rawRecord->service_request_id);

                if ($existingReport) {
                    if ($existingReport->get('field_status')->value == 'closed') {
                        // This thing is already closed both there and here. Nothing to update.
                    }
                    else {
                        $existingReport->get('field_status');
                        $status_notes = (property_exists($rawRecord, 'status_notes')) ? $rawRecord->status_notes : 'No closing notes provided';
                        $updated_datetime = (property_exists($rawRecord, 'updated_datetime')) ? $rawRecord->updated_datetime : $rawRecord->requested_datetime;
                        $updateRecord = new UpdateRecord(
                            $existingReport,
                            $status_notes,
                            $updated_datetime,
                            'closed'
                        );
                        $updateRecord->updateReportData();
                        if ($updatedRecord = $updateRecord->saveUpdatedExistingReport()) {
                            $this->openLast50RecordsClosed++;
                        }
                    }
                }
                else {
                    // Hey! We found a new record the easy way! Let's save it.
                    $this->rawRecord = $rawRecord;
                    if ($this->isValidRawRecord()) {
                        $this->serviceRequestId = $rawRecord->service_request_id;
                        $this->saveRecord(); {
                            $this->recordsSaved++;
                        }
                    }
                }
            }
        }
    }
    
    private function doUpdateExistingOpenRecords() {
        $this->openRecordsRecentNids = $this->findOpenRecords(28);
        $this->updateOpenRecords($this->openRecordsRecentNids, 'Recent');

        $this->openRecordsOlderNids = $this->findOpenRecords(180);
        $this->updateOpenRecords($this->openRecordsOlderNids, 'Older');
    }

    private function updateOpenRecords($openNids, $name = 'Recent') {
        foreach ($openNids as $openRecordNid) {
            $typeString = 'open' . $name . 'RecordsClosed';
            $node = Node::load($openRecordNid);
            $serviceRequestId = $node->get('field_service_request_id')->value;
            $response = Response::fetch(
                "https://mayors24.cityofboston.gov/open311/v2/requests.json?service_request_id=$serviceRequestId&api_key=$this->apiKey"
            );
            $this->apiRequestsMade++;
            $rawRecord = reset($response);

            if ($rawRecord->status != 'closed') {
                // This report is still open. Move on.
                continue;
            }
            else {
                $status_notes = (property_exists($rawRecord, 'status_notes')) ? $rawRecord->status_notes : 'No closing notes provided';
                $updated_datetime = (property_exists($rawRecord, 'updated_datetime')) ? $rawRecord->updated_datetime : $rawRecord->requested_datetime;
                $updateRecord = new UpdateRecord(
                    $node,
                    $status_notes,
                    $updated_datetime,
                    'closed'
                );
                $updateRecord->updateReportData();
                if ($updatedRecord = $updateRecord->saveUpdatedExistingReport()) {
                    $this->$typeString++;
                }
            }
        }
    }

    private function fetchRawRecord() {
        $rawRecord = Response::fetch("https://mayors24.cityofboston.gov/open311/v2/requests.json?service_request_id=$this->serviceRequestId&api_key=$this->apiKey");
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
        $response = Response::fetch("https://mayors24.cityofboston.gov/open311/v2/requests.json?api_key=$this->apiKey");
        $recordToUse = reset($response);

        foreach ($response as $record) {
            // If one of the first 50 responses is open, use that request ID as the starting point since it will be higher
            // than any recently closed ones. If not, fall back to the first record.
            if ($record->status == "open") {
                $recordToUse = $record;
                break;
            }
        }

        $highestRemoteServiceRequestId = substr($recordToUse->service_request_id, -12);

        $this->highestRemoteServiceRequestId = $highestRemoteServiceRequestId;
    }

    private function findHighestLocalServiceRequestId() {
        $highestAttemptedLocalServiceRequestId = \Drupal::state()->get('highest-attempted-service-request-id', false);

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
        $this->highestLocalServiceRequestId = ($highestLocalServiceRequestId > $highestAttemptedLocalServiceRequestId) ? $highestLocalServiceRequestId : $highestAttemptedLocalServiceRequestId;
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

    private function findRecordByServiceRequestId($serviceRequestId) {
        $query = \Drupal::entityQuery('node');
        $query->condition('field_service_request_id', $serviceRequestId);
        $results = $query->execute();
        if ($results) {
            if (count($results) > 1) {
                throw new \Exception('Found more than one entity with the same Service Request ID: ' . $serviceRequestId);
            }
            return \Drupal::entityTypeManager()->getStorage('node')->load(reset($results));
        }
        return false;
    }

    /**
     * @param int $daysOld
     *   The number of days old reports can be.
     *
     * @return array
     *   An array of node ids.
     */
    private function findOpenRecords(int $daysOld) {
        $query = \Drupal::database()->select('node_field_data', 'n');
        $query->join('node__field_status', 'nfs', 'n.nid = nfs.entity_id');
        $query->join('node__field_requested_timestamp', 'nfr', 'n.nid = nfr.entity_id');
        $query->fields('n', ['nid']);
        $query->condition('type', 'report');
        $query->condition('nfs.field_status_value', 'open');
        $query->condition('nfr.field_requested_timestamp_value', time() - ($daysOld * 24 * 60 * 60), '>');
        $query->range(0, $this->numberOfExistingOpenRecordsToUpdate);
        $query->orderRandom();

        return $query->execute()->fetchCol();
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
            'Recent records now closed' => $this->openRecentRecordsClosed,
            'Older records now closed' => $this->openOlderRecordsClosed,
            'Last 50 records now closed' => $this->openLast50RecordsClosed,
            'Start LI:' => $this->highestLocalServiceRequestId,
            'Start FI:' => $this->lowestLocalServiceRequestId,
            'Execution time in seconds' => (time() - $this->timestart),
        ];

        $message = '';

        foreach ($messageParts as $name => $stat) {
            $message = $message . "<li><strong>$name:</strong> $stat</li>";
        }
        $message = "<ul>$message</ul>";

        \Drupal::logger('Boston 311 Reports')->notice($message);
    }

    private function ageOfOldestReportToUpdate() {
        // Two weeks.
        return time() - (14 * 24 * 60 * 60);
    }

    private function setApiKey() {
        if (file_exists('/Users/butler/keys/bos311apikey')) {
            return $this->apiKey = rtrim(file_get_contents('/Users/butler/keys/bos311apikey'));
        }
        if (file_exists('/var/www/bos311apikey')) {
            return $this->apiKey = rtrim(file_get_contents('/var/www/bos311apikey'));
        }
        throw new \Exception('Unable to locate API key.');
    }

}
