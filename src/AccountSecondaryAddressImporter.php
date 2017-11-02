<?php

namespace SonarSoftware\Importer;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
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

            $validData = [];

            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $this->buildPayload($data, (bool)$validateAddress);
                array_push($validData, $data);
            }

            $requests = function () use ($validData, $validateAddress)
            {
                foreach ($validData as $validDatum)
                {
                    if (count($validDatum[0]) > 0)
                    {
                        yield new Request("POST", $this->uri . "/api/v1/accounts/" . (int)trim($validDatum[0]) . "/addresses", [
                                'Content-Type' => 'application/json; charset=UTF8',
                                'timeout' => 30,
                                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                            ]
                            , json_encode($this->buildPayload($validDatum, (bool)$validateAddress)));
                    }
                }
            };

            $pool = new Pool($this->client, $requests(), [
                'concurrency' => 10,
                'fulfilled' => function ($response, $index) use (&$returnData, $successLog, $failureLog, $validData)
                {
                    $statusCode = $response->getStatusCode();
                    if ($statusCode > 201)
                    {
                        $body = json_decode($response->getBody()->getContents());
                        $line = $validData[$index];
                        array_push($line,$body);
                        fputcsv($failureLog,$line);
                        $returnData['failures'] += 1;
                    }
                    else
                    {
                        $returnData['successes'] += 1;
                        fwrite($successLog,"Secondary address import succeeded for account ID {$validData[$index][0]}" . "\n");
                    }
                },
                'rejected' => function($reason, $index) use (&$returnData, $failureLog, $validData)
                {
                    $response = $reason->getResponse();
                    if ($response)
                    {
                        $body = json_decode($response->getBody()->getContents());
                        $returnMessage = implode(", ",(array)$body->error->message);
                    }
                    else
                    {
                        $returnMessage = "No response returned from Sonar.";
                    }
                    $line = $validData[$index];
                    array_push($line,$returnMessage);
                    fputcsv($failureLog,$line);
                    $returnData['failures'] += 1;
                }
            ]);

            $promise = $pool->promise();
            $promise->wait();

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
    private function buildPayload($data, $validateAddress = false)
    {
        $unformattedAddress = [
            'line1' => trim($data[2]),
            'line2' => trim($data[3]),
            'city' => trim($data[4]),
            'state' => strlen(trim($data['5'])) === 2 ? strtoupper(trim($data[5])) : trim($data[5]),
            'county' => trim($data[6]),
            'zip' => trim($data[7]),
            'country' => trim($data[8]),
            'latitude' => trim($data[9]),
            'longitude' => trim($data[10]),
        ];

        if ($validateAddress === true)
        {
            $formattedAddress = $this->addressFormatter->formatAddress($unformattedAddress, $validateAddress, false);
        }
        else
        {
            $formattedAddress = $unformattedAddress;
        }

        $formattedAddress['address_type_id'] = (int)trim($data[1]);

        return $formattedAddress;
    }
}