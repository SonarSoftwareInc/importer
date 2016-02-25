<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;

class Importer
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
    }

    /**
     * @param $pathToImportFile - Input the full path to the accounts CSV file, which should be generated based on the template in 'templates'.
     * @param int $debitAdjustmentID
     * @param int $creditAdjustmentID
     * @return array
     */
    public function importAccounts($pathToImportFile, $debitAdjustmentID, $creditAdjustmentID)
    {
        set_time_limit(0);
        $this->validateCredentials();
        $this->validateVersion("0.3.2");

        $accountImporter = new AccountImporter();
        return $accountImporter->import($pathToImportFile, $debitAdjustmentID, $creditAdjustmentID);
    }

    /**
     * Validate that the version of the remote Sonar instance is valid.
     * @param $requiredVersion
     * @return bool
     */
    private function validateVersion($requiredVersion)
    {
        $response = $this->client->get($this->uri . "/api/v1/_data/version", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
        ]);

        $responseData = json_decode($response->getBody());

        if ($this->equalToOrNewerThanVersion($responseData->data->version,$requiredVersion) !== true)
        {
            throw new InvalidArgumentException("Invalid Sonar version, this importer requires version $requiredVersion or higher.");
        }

        return true;
    }

    /**
     * Validate the credentials. This will throw an exception on failure.
     * @return bool
     */
    private function validateCredentials()
    {
        try {
            $this->client->get($this->uri . "/api/v1/_data/version", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
            ]);
        }
        catch (Exception $e)
        {
            throw new InvalidArgumentException("Your credentials appear to be invalid or the Sonar server is inaccessible. Specific error is '{$e->getMessage()}'");
        }

        return true;
    }

    /**
     * @param $currentVersion - Version of the Sonar system
     * @param $versionToCheck - Version that is required
     * @return bool
     */
    private function equalToOrNewerThanVersion($currentVersion, $versionToCheck)
    {
        $currentVersionArray = explode(".",$currentVersion);
        $versionToCheckArray = explode(".",$versionToCheck);

        //1.0.0 is older than 2.0.0
        if ($currentVersionArray[0] < $versionToCheckArray[0])
        {
            return false;
        }

        if ($currentVersionArray[0] > $versionToCheckArray[0])
        {
            return true;
        }

        //Same major version
        if ($currentVersionArray[0] == $versionToCheckArray[0])
        {
            if ($currentVersionArray[1] < $versionToCheckArray[1])
            {
                return false;
            }

            if ($currentVersionArray[1] > $versionToCheckArray[1])
            {
                return true;
            }

            if ($currentVersionArray[1] == $versionToCheckArray[1])
            {
                if ($currentVersionArray[2] < $versionToCheckArray[2])
                {
                    return false;
                }

                if ($currentVersionArray[2] >= $versionToCheckArray[2])
                {
                    return true;
                }
            }
        }

        return false;
    }

}