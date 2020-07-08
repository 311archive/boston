<?php


namespace Drupal\bos311;

use Drupal\node\Entity\Node;

class UpdateRecord
{
    private Node $existingReport;
    private string $status_notes;
    private $updated_datetime;
    private string $status;

    public function __construct(Node $existingReport, $status_notes, $updated_datetime, $status)
    {
        $this->existingReport = $existingReport;
        $this->status_notes = $status_notes;
        $this->updated_datetime = Record::formatDateTime($updated_datetime);
        $this->status = $status;
    }

    /**
     * Given an existing report, only update the fields that are likely to change.
     * @param Node $existingReport
     * @return mixed
     */
    public function updateReportData() {
        $this->existingReport->field_status_notes = Record::cleanChars($this->status_notes);
        $this->existingReport->set('field_updated_timestamp', $this->updated_datetime);
        $this->existingReport->set('field_updated_datetime', Record::formatIso8601($this->updated_datetime));
        $this->existingReport->field_status = $this->status;

        return $this->existingReport;
    }

    public function saveUpdatedExistingReport()
    {
        $this->existingReport->save();
        return $this->existingReport;
    }

}