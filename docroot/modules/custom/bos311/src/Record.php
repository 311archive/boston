<?php

namespace Drupal\bos311;

use \Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

class Record
{
    private $service_request_id;
    private $status;
    private $service_name;
    private $description;
    private $status_notes;
    private $requested_datetime;
    private $address;
    private $lat;
    private $long;
    private $media_url;
    private $updated_datetime;
    private $locationData;

    private $apiKey;

    private array $values;

    private $existingReport;

    private $nominatimServer = 'https://nominatim.openstreetmap.org';
    private $googleServer = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * Record constructor.
     * @param array $rawRecord
     * @param integer $serviceRequestId
     */
    public function __construct($rawRecord, $serviceRequestId)
    {
        foreach ($rawRecord as $key => $value) {
            $this->$key = $this->cleanChars($value);
        }
        // Always use the Service Request ID that was used to fetch the record rather than the one provided by the
        // record because the one's provided can be inconsistent.
        $this->service_request_id = $serviceRequestId;
        $this->validateTimestampFields();
        $this->checkForExistingReport();
        $this->fetchLocationData();
        $this->gatherValues();
    }

    public function saveRecord() {
        if ($this->existingReport) {
            $node = $this->existingReport;
            $node = $this->updateReportData($node);
        }
        else {
            $node = Node::create($this->values);
        }
        $node->save();
        return $node;
    }

    private function gatherValues() {
        if ($this->existingReport) {
            return;
        }
        $values = [];
        $values['type'] = 'report';
        $values['title'] = $this->service_name . ' at ' . $this->address;
        $values['field_service_request_id'] = $this->service_request_id;
        $values['field_description'] = ($this->description) ? $this->description : $this->service_name;
        $values['field_status_notes'] = $this->status_notes;
        $values['field_status'] = $this->status;
        $values['field_requested_timestamp'] = $this->requested_datetime;
        $values['field_requested_datetime'] = $this->formatIso8601($this->requested_datetime);
        $values['field_updated_timestamp'] = $this->updated_datetime;
        $values['field_updated_datetime'] = $this->formatIso8601($this->updated_datetime);
        $values['field_address'] = $this->address;
        $values['field_latitude'] = $this->lat;
        $values['field_longitude'] = $this->long;
        $values['field_media_url'] = $this->media_url;
        $values['field_service_name'] = [
            'target_id' => $this->mapVocabTerm($this->service_name, 'service'),
        ];
        $values['field_neighborhood'] = [
            'target_id' => $this->mapVocabTerm($this->findNeighborhoodName(), 'neighborhoods'),
        ];
        $values['field_zip_code'] = $this->findZip();

        $this->values = $values;
    }

    /**
     * Given an existing report, only update the fields that are likely to change.
     * @param $existingReport
     * @return mixed
     */
    private function updateReportData($existingReport) {
        $existingReport->field_status_notes = $this->cleanChars($this->status_notes);
        $existingReport->field_updated_timestamp = $this->updated_datetime;
        $existingReport->field_updated_datetime = $this->formatIso8601($this->updated_datetime);
        $existingReport->field_status = $this->status;

        return $existingReport;
    }

    private function fetchLocationData() {
        if ($this->existingReport) {
            return;
        }
        $url = "$this->nominatimServer/reverse?lat=$this->lat&lon=$this->long&format=json";
        $response = Response::fetch($url);

        $this->locationData = $response;
    }

    private function checkForExistingReport() {
        $nids = \Drupal::entityQuery('node')
            ->condition('type','report')
            ->condition('field_service_request_id', $this->service_request_id)
            ->execute();
        if ($nids) {
            $node = \Drupal::entityTypeManager()->getStorage('node')->load(reset($nids));
            if ($node) {
                $this->existingReport = $node;
            }
        }
    }

    /**
     * Maps a string term name to vocab's taxonomy term.
     *
     * @param string $termName
     *   The name of the taxonomy term.
     * @param string $vocabName
     *   The name of the vocabulary
     *
     * @return int
     *   The ID of the vocab's taxonomy term.
     * @throws \Exception
     *   If an ambiguous term is provided.
     */
    private function mapVocabTerm($termName, $vocabName) {
        $term = \Drupal::entityTypeManager()
            ->getStorage('taxonomy_term')
            ->loadByProperties([
                'name' => $termName,
                'vid' => $vocabName,
            ]);

        if ($term) {
            // Already exists. Return existing term's ID.
            if (count($term) > 1) {
                // @todo isn't this dangerous? What if there's more than one term in different vocabs?
                throw new \Exception("Time to make sure the term is in a specific vocab instead of just searching by name.");
            }
            return reset($term)->id();
        }

        // Doesn't exist, let's create it and return the new term's ID.
        $values = [
            'name' => $termName,
            'vid' => $vocabName,
        ];
        $term = Term::create($values);
        $term->save();
        return $term->id();
    }

    private function findZip() {
        $default = '00000';
        if (!is_object($this->locationData)) {
            return $default;
        }
        if (!is_object($this->locationData->address)) {
            return $default;
        }
        if (!property_exists($this->locationData->address, 'postcode')) {
            return $default;
        }
        return $this->locationData->address->postcode;
    }

    private function findNeighborhoodName() {
        $neighborhoodName = 'unknown';
        if ($neighborhood = $this->extractNeighborhoodFromAddress()) {
            $neighborhoodName = $neighborhood;
        }
        elseif (is_object($this->locationData->address)) {
            if (property_exists($this->locationData->address, 'suburb')) {
                $neighborhood = $this->locationData->address->suburb;
                if (in_array($neighborhood, $this->neighborhoods)) {
                    $neighborhoodName = $neighborhood;
                }
                elseif (property_exists($this->locationData->address, 'city')) {
                    $neighborhoodName = $neighborhood = $this->locationData->address->city;
                }
                elseif (property_exists($this->locationData->address, 'town')) {
                    $neighborhoodName = $neighborhood = $this->locationData->address->town;
                }
            }
            elseif (property_exists($this->locationData->address, 'city')) {
                $neighborhoodName = $neighborhood = $this->locationData->address->city;
            }
            elseif (property_exists($this->locationData->address, 'town')) {
                $neighborhoodName = $neighborhood = $this->locationData->address->town;
            }
            if (property_exists($this->locationData->address, 'neighbourhood')) {
                if (in_array($this->locationData->address->neighbourhood, $this->neighborhoods)) {
                    $neighborhoodName = $this->locationData->address->neighbourhood;
                }
                elseif ($this->locationData->address->neighbourhood == 'Lower Mills') {
                    $neighborhoodName = 'Dorchester';
                }
                elseif ($this->locationData->address->neighbourhood == 'Neponset') {
                    $neighborhoodName = 'Dorchester';
                }
                elseif ($this->locationData->address->neighbourhood == 'Readville') {
                    $neighborhoodName = 'Hyde Park';
                }
                elseif ($this->locationData->address->neighbourhood == 'Orient Heights') {
                    $neighborhoodName = 'East Boston';
                }
                elseif ($this->locationData->address->neighbourhood == 'Highland') {
                    $neighborhoodName = 'West Roxbury';
                }
                elseif ($this->locationData->address->neighbourhood == 'Stonybrook Village') {
                    $neighborhoodName = 'Hyde Park';
                }
            }
        }

        if ($neighborhoodName == 'unknown') {
            // We could use the Google API sparingly when the other one fails.
            //$locationData = Response::fetch($this->googleServer . "?latlng=$this->lat,$this->long&key=$this->apiKey");
        }

        // Normalize the weird ones that I know about.
        switch ($neighborhoodName) {
            case 'Orient Heights':
                $neighborhoodName = 'East Boston';
                break;
            case 'Highland':
                $neighborhoodName = 'West Roxbury';
                break;
            case 'Mattapan':
                $neighborhoodName = 'Mattapan';
                break;
            case 'Lower Mills':
            case 'Neponset':
                $neighborhoodName = 'Dorchester';
                break;
            case 'Stonybrook Village':
                $neighborhoodName = 'Hyde Park';
                break;
        }

        if ($this->findZip() == '02210') {
            $neighborhoodName = 'Seaport / Fort Point';
        }

        return $neighborhoodName;

    }

    private function validateTimestampFields() {
        $timestampFields = [
            'requested_datetime',
            'updated_datetime',
        ];

        foreach ($timestampFields as $timestampField) {
            $this->$timestampField = self::formatDateTime($this->$timestampField);
        }
    }

    /**
     * Validates that a string is a valid ISO 8601 date string.
     * @param $date
     * @return bool
     */
    public static function formatDateTime($date)
    {
        $date = strtotime($date);
        if ($date === false) {
            self::formatDateTime(mktime(time()));
        }
        return $date;
    }

    private function formatIso8601($timestamp) {
        return date('Y-m-d\TH:i:s', ($timestamp + 14400));
    }

    private function cleanChars($string) {
        $cleanString = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $string);
        return $cleanString;
    }

    private function extractNeighborhoodFromAddress() {
        foreach ($this->neighborhoods as $neighborhood) {
            if (strpos($this->address, $neighborhood) !== false) {
                return $neighborhood;
            }
        }
        return;
    }

    private function setGoogleApiKey() {
        $apiKey = file_get_contents($_SERVER['HOME'] . '/keys/google-geolocation-api.key');
        $this->apiKey = $apiKey;
    }

    private $neighborhoods = [
        'Hyde Park',
        'Jamaica Plain',
        'Mattapan',
        'Mission Hill',
        'North End',
        'Roslindale',
        'Roxbury',
        'South Boston',
        'South End',
        'West End',
        'West Roxbury',
        'Allston',
        'Back Bay',
        'Bay Village',
        'Beacon Hill',
        'Brighton',
        'Charlestown',
        'Chinatown',
        'Leather District',
        'Dorchester',
        'Downtown',
        'East Boston',
        'Fenway',
        'Financial District'
    ];

}
