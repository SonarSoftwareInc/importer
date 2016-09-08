<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class AccountSecondaryAddressImporter extends AccessesSonar
{
    private $addressFormatter;

    /**
     * Importer constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->addressFormatter = new AddressFormatter();
    }

    /**
     * @param $pathToImportFile
     * @param bool $validateAddress
     * @return array
     */
    public function import($pathToImportFile, $validateAddress = false)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($pathToImportFile);

            $failureLogName = tempnam(getcwd() . "/log_output","account_secondary_address_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(getcwd() . "/log_output","account_secondary_address_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $row = 0;
            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $row++;
                try {
                    $this->createSecondaryAddress($data, $validateAddress);
                }
                catch (ClientException $e)
                {
                    $response = $e->getResponse();
                    $body = json_decode($response->getBody());
                    $returnMessage = implode(", ",(array)$body->error->message);
                    array_push($data,$returnMessage);
                    fputcsv($failureLog,$data);
                    $returnData['failures'] += 1;
                    continue;
                }
                catch (Exception $e)
                {
                    array_push($data,$e->getMessage());
                    fputcsv($failureLog,$data);
                    $returnData['failures'] += 1;
                    continue;
                }

                $returnData['successes'] += 1;
                fwrite($successLog,"Row $row succeeded for account ID " . trim($data[0]) . "\n");
            }
        }
        else
        {
            throw new InvalidArgumentException("File could not be opened.");
        }

        fclose($failureLog);
        fclose($successLog);

        return $returnData;
    }

    /**
     * Validate all the data in the import file.
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [ 0,1,2,4,5,7,8 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the account secondary address import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }
            }
        }
        else
        {
            throw new InvalidArgumentException("Could not open import file.");
        }

        return;
    }

    /**
     * @param $data
     * @param $validateAddress
     * @return array
     */
    private function buildPayload($data, $validateAddress)
    {
        $unformattedAddress = [
            'line1' => trim($data[2]),
            'line2' => trim($data[3]),
            'city' => trim($data[4]),
            'state' => trim($data[5]),
            'county' => trim($data[6]),
            'zip' => trim($data[7]),
            'country' => trim($data[8]),
            'latitude' => trim($data[9]),
            'longitude' => trim($data[10]),
        ];

        $formattedAddress = $this->addressFormatter->formatAddress($unformattedAddress, $validateAddress);

        $formattedAddress['address_type_id'] = (int)trim($data[1]);

        return $formattedAddress;
    }

    /**
     * @param $data
     * @param $validateAddress
     * @return mixed
     */
    private function createSecondaryAddress($data, $validateAddress)
    {
        $payload = $this->buildPayload($data, $validateAddress);
        $accountID = (int)trim($data[0]);

        return $this->client->post($this->uri . "/api/v1/accounts/$accountID/addresses", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
            'json' => $payload,
        ]);
    }
}