<?php

namespace SonarSoftware\Importer;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Exception;
use GuzzleHttp\Exception\ClientException;
use Carbon\Carbon;
use SonarSoftware\Importer\Extenders\AccessesSonar;

class AddressValidator extends AccessesSonar
{
    private $addressFormatter;
    private $redisClient;

    public function __construct()
    {
        parent::__construct();
        $this->redisClient = new \Predis\Client();
        $this->addressFormatter = new AddressFormatter();
    }

    /**
     * Validate the address in an account import
     * @param $pathToImportFile
     * @return array
     */
    public function validate($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile, "r")) !== FALSE) {
            $tempFile = tempnam(getcwd(),"validatedAddresses");
            $tempHandle = fopen($tempFile, "w");

            $this->validateImportFile($pathToImportFile);

            $addressFormatter = new AddressFormatter();

            $failureLogName = tempnam(getcwd() . "/log_output", "address_validator_failures");
            $failureLog = fopen($failureLogName, "w");

            $successLogName = tempnam(getcwd() . "/log_output", "address_validator_successes");
            $successLog = fopen($successLogName, "w");

            $returnData = [
                'validated_file' => $tempFile,
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            $addressesWithoutCounty = [];
            $addressesWithCounty = [];
            $validData = [];

            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                array_push($addressesWithoutCounty, $this->cleanAddress($data,false));
                array_push($addressesWithCounty, $this->cleanAddress($data,true));
                array_push($validData, $data);
            }

            $requests = function () use ($addressesWithoutCounty)
            {
                foreach ($addressesWithoutCounty as $addressWithoutCounty)
                {
                    if ($this->redisClient->exists($this->generateAddressKey($addressWithoutCounty)))
                    {
                        continue;
                    }

                    yield new Request("POST", $this->uri . "/api/v1/_data/validate_address", [
                            'Content-Type' => 'application/json; charset=UTF8',
                            'timeout' => 30,
                            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                        ]
                        , json_encode($addressWithoutCounty));
                }
            };


            $pool = new Pool($this->client, $requests(), [
                'concurrency' => 10,
                'fulfilled' => function ($response, $index) use (&$returnData, $successLog, $failureLog, $validData, $tempHandle, $addressFormatter, $addressesWithCounty, $addressesWithoutCounty)
                {
                    $statusCode = $response->getStatusCode();
                    if ($statusCode > 201)
                    {
                        //Need to check if the address with county is valid so we can at least use that
                        try {
                            $addressAsArray = $addressFormatter->doChecksOnUnvalidatedAddress($addressesWithCounty[$index]);
                            $returnData['successes'] += 1;
                            fwrite($successLog,"Validation succeeded for ID {$validData[$index][0]}" . "\n");
                            fputcsv($tempHandle, $this->mergeRow($addressAsArray, $validData[$index]));
                        }
                        catch (Exception $e)
                        {
                            $body = json_decode($response->getBody()->getContents());
                            $line = $validData[$index];
                            array_push($line,$body);
                            fputcsv($failureLog,$line);
                            $returnData['failures'] += 1;
                        }
                    }
                    else
                    {
                        $returnData['successes'] += 1;
                        fwrite($successLog,"Validation succeeded for ID {$validData[$index][0]}" . "\n");
                        $addressObject = json_decode($response->getBody()->getContents());

                        $this->redisClient->set($this->generateAddressKey($addressesWithoutCounty[$index]),json_encode((array)$addressObject->data));
                        $this->redisClient->expire($this->generateAddressKey($addressesWithoutCounty[$index]),604800);

                        $addressAsArray = (array)$addressObject->data;
                        fputcsv($tempHandle, $this->mergeRow($addressAsArray, $validData[$index]));
                    }
                },
                'rejected' => function($reason, $index) use (&$returnData, $failureLog, $validData, $addressFormatter, $addressesWithCounty, $successLog, $tempHandle)
                {
                    //Need to check if the address with county is valid so we can at least use that
                    try {
                        $addressAsArray = $addressFormatter->doChecksOnUnvalidatedAddress($addressesWithCounty[$index]);
                        $returnData['successes'] += 1;
                        fwrite($successLog,"Validation succeeded for ID {$validData[$index][0]}" . "\n");
                        fputcsv($tempHandle, $this->mergeRow($addressAsArray, $validData[$index]));
                    }
                    catch (Exception $e)
                    {
                        $response = $reason->getResponse();
                        $body = json_decode($response->getBody()->getContents());
                        $returnMessage = implode(", ",(array)$body->error->message);
                        $line = $validData[$index];
                        array_push($line,$returnMessage);
                        fputcsv($failureLog,$line);
                        $returnData['failures'] += 1;
                    }
                }
            ]);

            //Go through the addresses and check if they are in the cache. If so, add the data to the document.
            foreach ($addressesWithoutCounty as $index => $addressWithoutCounty)
            {
                if ($this->redisClient->exists($this->generateAddressKey($addressWithoutCounty)))
                {
                    $data = $this->redisClient->get($this->generateAddressKey($addressWithoutCounty));
                    $returnData['successes'] += 1;
                    fwrite($successLog,"Validation succeeded for ID {$validData[$index][0]}" . "\n");
                    fputcsv($tempHandle, $this->mergeRow((array)json_decode($data), $validData[$index]));
                }
            }

            $promise = $pool->promise();
            $promise->wait();

        }
        else
        {
            throw new InvalidArgumentException("File could not be opened.");
        }

        fclose($tempHandle);
        fclose($failureLog);
        fclose($successLog);

        return $returnData;
    }

    /**
     * @param $validatedAddress
     * @param $currentRow
     */
    private function mergeRow($validatedAddress, $currentRow)
    {
        $currentRow[7] = $validatedAddress['line1'];
        $currentRow[9] = $validatedAddress['city'];
        $currentRow[10] = $validatedAddress['state'];
        $currentRow[11] = $validatedAddress['county'];
        $currentRow[12] = $validatedAddress['zip'];
        $currentRow[13] = $validatedAddress['country'];
        if (!trim($currentRow[14]))
        {
            $currentRow[14] = $validatedAddress['latitude'];
        }
        if (!trim($currentRow[15]))
        {
            $currentRow[15] = $validatedAddress['longitude'];
        }
        return $currentRow;
    }

    /**
     * Either cleans up the address, or throws an exception with a failure message
     * @param $data
     * @param bool $withCounty
     * @return mixed
     */
    private function cleanAddress($data, $withCounty = false)
    {
        return  [
            'line1' => trim($data[7]),
            'city' => trim($data[9]) ? trim($data[9]) : getenv("DEFAULT_CITY"),
            'state' => strtoupper(trim($data[10])),
            'county' => $withCounty === false ? null : trim($data[11]),
            'zip' => trim($data[12]),
            'country' => trim($data[13]),
        ];
    }

    /**
     * Validate all the data in the import file.
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [ 7,9,10,13 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if ($colNumber === 0 && getenv('DEFAULT_CITY'))
                    {
                        continue;
                    }
                    
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the address validation call, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
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
     * Generate an address key for caching
     * @param array $cleanedAddress
     * @return null
     */
    private function generateAddressKey(array $cleanedAddress)
    {
        $key = null;
        $key .= preg_replace("/[^A-Za-z0-9]/", '', $cleanedAddress['line1']);
        $key .= preg_replace("/[^A-Za-z0-9]/", '', $cleanedAddress['city']);
        $key .= preg_replace("/[^A-Za-z0-9]/", '', $cleanedAddress['state']);
        $key .= preg_replace("/[^A-Za-z0-9]/", '', $cleanedAddress['zip']);

        return $key;
    }
}