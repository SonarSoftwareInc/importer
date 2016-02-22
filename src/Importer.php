<?php

namespace SonarSoftware\Importer;

use InvalidArgumentException;

class Importer
{
    private $uri;
    private $username;
    private $password;

    private $accountColumns = [
        0 => 'ID',
        1 => 'Name',
        2 => 'Account Type ID',
        3 => 'Account Status ID',
        4 => 'Account Groups',
        5 => 'Sub Accounts',
        6 => 'Next Bill Date',
        7 => 'Physical Address Line 1',
        8 => 'Physical Address Line 2',
        9 => 'Physical City',
        10 => 'Physical State',
        11 => 'Physical County',
        12 => 'Physical ZIP',
        13 => 'Physical Country',
        14 => 'Physical Latitude',
        15 => 'Physical Longitude',
        16 => 'Primary Contact Name',
        17 => 'Primary Contact Role',
        18 => 'Primary Contact Email Address',
        19 => 'Primary Contact Phone Numbers',
        20 => 'Primary Contact Email Message Categories',
    ];

    /**
     * Importer constructor.
     */
    public function __construct()
    {
        $dotenv = new Dotenv\Dotenv(__DIR__);
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
    }

    /**
     * @param $pathToImportFile - Input the full path to the accounts CSV file.
     * @return array
     */
    public function importAccounts($pathToImportFile)
    {
        $this->validateCredentials();
        $this->validateVersion("0.3.0");

        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $client = new GuzzleHttp\Client();

            $failureLogName = tempnam(__DIR__,"account_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(__DIR__,"account_import_successes");
            $successLog = fopen($successLogName,"w");

            $returnData = [
                'successes' => 0,
                'failures' => 0,
                'failure_log_name' => $failureLogName,
                'success_log_name' => $successLogName,
            ];

            while (($data = fgetcsv($handle, 8096, ",")) !== FALSE) {
                $payload = [
                    'name' => '',
                ];
            }
        }
        else
        {
            throw new InvalidArgumentException("File could not be opened.");
        }

        return $returnData;
    }

    /**
     * Validate that the version of the remote Sonar instance is equal to or higher than what we need.
     * @param $requiredVersion
     */
    private function validateVersion($requiredVersion)
    {
        throw new InvalidArgumentException("Invalid version, this importer requires version $requiredVersion or higher.");
    }

    /**
     * Validate the entered credentials.
     */
    private function validateCredentials()
    {
        throw new InvalidArgumentException("Invalid credentials.");
    }

}