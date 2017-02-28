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
    private $dataToBeImported = [];

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
                'cache_hits' => 0,
                'cache_fails' => 0,
            ];

            $addressesWithCounty = [];
            $addressesWithoutCounty = [];
            $validData = [];

            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                array_push($addressesWithoutCounty, $this->cleanAddress($data,false));
                array_push($addressesWithCounty, $this->cleanAddress($data,true));
                array_push($validData, $data);
            }

            $cacheHits = 0;
            $cacheFails = 0;

            $requests = function () use ($addressesWithoutCounty, &$cacheHits, &$cacheFails, $validData)
            {
                foreach ($addressesWithoutCounty as $index => $addressWithoutCounty)
                {
                    $key = $this->generateAddressKey($validData[$index]);
                    if ($this->redisClient->exists($key))
                    {
                        $cacheHits++;
                        continue;
                    }

                    $cacheFails++;
                    array_push($this->dataToBeImported,$validData[$index]);
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
                'fulfilled' => function ($response, $index) use (&$returnData, $successLog, $failureLog, $validData, $tempHandle, $addressFormatter, $addressesWithCounty)
                {
                    $statusCode = $response->getStatusCode();
                    if ($statusCode > 201)
                    {
                        //Need to check if the address with county is valid so we can at least use that
                        try {
                            $addressAsArray = $addressFormatter->doChecksOnUnvalidatedAddress($addressesWithCounty[$index],true);

                            $this->redisClient->set($this->generateAddressKey($this->dataToBeImported[$index]),json_encode($addressAsArray));
                            $this->redisClient->expire($this->generateAddressKey($this->dataToBeImported[$index]),18144000);
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
                        $addressObject = json_decode($response->getBody()->getContents());

                        $this->redisClient->set($this->generateAddressKey($this->dataToBeImported[$index]),json_encode((array)$addressObject->data));
                        $this->redisClient->expire($this->generateAddressKey($this->dataToBeImported[$index]),18144000);
                    }
                },
                'rejected' => function($reason, $index) use (&$returnData, $failureLog, $validData, $addressFormatter, $successLog, $tempHandle, $addressesWithCounty)
                {
                    //Need to check if the address with county is valid so we can at least use that
                    try {
                        $addressAsArray = $addressFormatter->doChecksOnUnvalidatedAddress($addressesWithCounty[$index],true);

                        $this->redisClient->set($this->generateAddressKey($this->dataToBeImported[$index]),json_encode($addressAsArray));
                        $this->redisClient->expire($this->generateAddressKey($this->dataToBeImported[$index]),18144000);
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

            $promise = $pool->promise();
            $promise->wait();

        }
        else
        {
            throw new InvalidArgumentException("File could not be opened.");
        }

        //Go through the addresses and check if they are in the cache. If so, add the data to the document.
        foreach ($addressesWithoutCounty as $index => $addressWithoutCounty)
        {
            if ($this->redisClient->exists($this->generateAddressKey($validData[$index])))
            {
                $data = $this->redisClient->get($this->generateAddressKey($validData[$index]));
                $returnData['successes'] += 1;
                fwrite($successLog,"Validation succeeded for ID {$validData[$index][0]}" . "\n");
                fputcsv($tempHandle, $this->mergeRow(json_decode($data,true), $validData[$index]));
            }
        }

        if (count($validData) != $returnData['successes'] + $returnData['failures'])
        {
            echo "WARNING: Validated address count does not match the total addresses entered!\n";
        }

        fclose($tempHandle);
        fclose($failureLog);
        fclose($successLog);

        $returnData['cache_hits'] = $cacheHits;
        $returnData['cache_fails'] = $cacheFails;

        return $returnData;
    }

    /**
     * @param $validatedAddress
     * @param $currentRow
     */
    private function mergeRow($validatedAddress, $currentRow)
    {
        if ($validatedAddress['line1'])
        {
            $currentRow[7] = $validatedAddress['line1'];
        }
        if ($validatedAddress['city'])
        {
            $currentRow[9] = $validatedAddress['city'];
        }
        if ($validatedAddress['state'])
        {
            $currentRow[10] = $validatedAddress['state'];
        }
        if ($validatedAddress['county'])
        {
            $currentRow[11] = $validatedAddress['county'];
        }
        if ($validatedAddress['zip'])
        {
            //Sometimes, the geocoder will return a shortened ZIP that is invalid. Keep the original one if that happens.
            if (strlen(str_replace(" ","",$validatedAddress['zip'])) >= strlen(str_replace(" ","",$currentRow[12])))
            {
                $currentRow[12] = $validatedAddress['zip'];
            }
        }
        if ($validatedAddress['country'])
        {
            $currentRow[13] = $validatedAddress['country'];
        }

        if (!trim($currentRow[14]) && isset($validatedAddress['latitude']))
        {
            $currentRow[14] = $validatedAddress['latitude'];
        }
        if (!trim($currentRow[15]) && isset($validatedAddress['longitude']))
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
        if (!isset($data[7]))
        {
            print_r($data);
        }
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
     * @param array $validDatum
     * @return null
     */
    private function generateAddressKey(array $validDatum)
    {
        return strtolower($this->uri . "_" . $validDatum[0]);
    }
}