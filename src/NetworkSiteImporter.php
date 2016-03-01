<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;

class NetworkSiteImporter
{
    private $uri;
    private $username;
    private $password;
    private $client;

    private $addressFormatter;

    /**
     * Importer constructor.
     */
    public function __construct()
    {
        $dotenv = new \Dotenv\Dotenv(__DIR__);
        $dotenv->load();
        $dotenv->required(
            [
                'URI',
                'USERNAME',
                'PASSWORD',
            ]
        );

        $this->uri = getenv("URI");
        $this->username = getenv("USERNAME");
        $this->password = getenv("PASSWORD");

        $this->client = new \GuzzleHttp\Client();

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

            if (!file_exists(__DIR__ . "/../log_output"))
            {
                mkdir(__DIR__ . "/../log_output");
            }

            $failureLogName = tempnam(__DIR__ . "/../log_output","network_site_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(__DIR__ . "/../log_output","network_site_import_successes");
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
                    $this->createNetworkSite($data, $validateAddress);
                }
                catch (ClientException $e)
                {
                    $response = $e->getResponse();
                    $body = json_decode($response->getBody());
                    $returnMessage = implode(", ",(array)$body->error->message);
                    fwrite($failureLog,"Row $row failed: $returnMessage\n");
                    $returnData['failures'] += 1;
                    continue;
                }
                catch (Exception $e)
                {
                    fwrite($failureLog,"Row $row failed: {$e->getMessage()}\n");
                    $returnData['failures'] += 1;
                    continue;
                }

                $returnData['successes'] += 1;
                fwrite($successLog,"Row $row succeeded for network site " . trim($data[0]) . "\n");
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
     * @param $pathToImportFile
     */
    private function validateImportFile($pathToImportFile)
    {
        $requiredColumns = [ 0,6 ];

        if (($fileHandle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $row = 0;
            while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
                $row++;
                foreach ($requiredColumns as $colNumber) {
                    if (trim($data[$colNumber]) == '') {
                        throw new InvalidArgumentException("In the network site import, column number " . ($colNumber + 1) . " is required, and it is empty on row $row.");
                    }
                }

                if ((!trim($data[1]) || !trim($data[3]) || !trim($data[4]) || !trim($data[5])) && (!trim($data[7]) || !trim($data[8])))
                {
                    print_r($data);
                    throw new InvalidArgumentException("You must provide either an address or a latitude/longitude. Both are missing on row $row.");
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
        $payload = [
            'name' => $data[0],
            'country' => $data[6],
        ];

        if (trim($data[7]) && trim($data[8]))
        {
            $payload['latitude'] = $data[7];
            $payload['longitude'] = $data[8];
        }

        $unformattedAddress = [
            'line1' => trim($data[1]),
            'line2' => trim($data[2]),
            'city' => trim($data[3]),
            'state' => trim($data[4]),
            'zip' => trim($data[5]),
            'country' => trim($data[6]),
            'latitude' => trim($data[7]),
            'longitude' => trim($data[8]),
        ];

        if ($unformattedAddress['line1'] && $unformattedAddress['city'] && $unformattedAddress['state'])
        {
            $formattedAddress = $this->addressFormatter->formatAddress($unformattedAddress, $validateAddress);
            $payload['line1'] = $formattedAddress['line1'];
            $payload['line2'] = $formattedAddress['line2'];
            $payload['city'] = $formattedAddress['city'];
            $payload['state'] = $formattedAddress['state'];
            $payload['zip'] = $formattedAddress['zip'];
            if (!array_key_exists("latitude",$payload))
            {
                $payload['latitude'] = $formattedAddress['latitude'];
                $payload['longitude'] = $formattedAddress['longitude'];
            }
        }

        return $payload;
    }

    /**
     * @param $data
     * @param $validateAddress
     * @return mixed
     */
    private function createNetworkSite($data, $validateAddress)
    {
        $payload = $this->buildPayload($data, $validateAddress);

        return $this->client->post($this->uri . "/api/v1/network/network_sites", [
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