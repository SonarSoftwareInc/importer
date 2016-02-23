<?php

namespace SonarSoftware\Importer;

use InvalidArgumentException;

class AccountImporter
{
    private $uri;
    private $username;
    private $password;
    private $client;

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

        $this->client = new GuzzleHttp\Client();
    }

    /**
     * @param $pathToImportFile
     * @return array
     * @throws InvalidArgumentException
     */
    public function import($pathToImportFile)
    {
        if (($handle = fopen($pathToImportFile,"r")) !== FALSE)
        {
            $this->validateImportFile($handle);

            $failureLogName = tempnam(__DIR__ . "/../log_output","account_import_failures");
            $failureLog = fopen($failureLogName,"w");

            $successLogName = tempnam(__DIR__ . "/../log_output","account_import_successes");
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
     * Validate all the data in the import file.
     * @param $fileHandle
     */
    private function validateImportFile($fileHandle)
    {
        $row = 0;
        while (($data = fgetcsv($fileHandle, 8096, ",")) !== FALSE) {
            $row++;
            if (count($data) !== 21)
            {
                throw new InvalidArgumentException("The account import document should have exactly 21 columns.");
            }

            $requiredColumns = [ 0,1,2,3,7,9,10,13,16 ];
            foreach ($requiredColumns as $colNumber)
            {
                if (trim($data[$colNumber]) == '')
                {
                    throw new InvalidArgumentException("In the account import, column number " . ($colNumber+1) . " is required, and it is empty on row $row.");
                }
            }
        }

        return;
    }
}