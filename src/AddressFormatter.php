<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;

class AddressFormatter
{
    private $uri;
    private $username;
    private $password;
    private $client;

    /**
     * Data stored to limit necessary queries
     */
    private $countries;
    private $subDivisions = [];
    private $counties = [];

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

        $this->loadCountryData();
    }

    /**
     * Load the country data into a local array.
     */
    private function loadCountryData()
    {
        $response = $this->client->get($this->uri . "/api/v1/_data/countries", [
            'headers' => [
                'Content-Type' => 'application/json; charset=UTF8',
                'timeout' => 30,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
        ]);

        $this->countries = json_decode($response->getBody())->data;
    }

    /**
     * Return a formatted address for use in the API import. Throws an InvalidArgumentException if something is invalid in the address.
     * @param $unformattedAddress
     * @return array
     */
    public function formatAddress($unformattedAddress)
    {
        if (!array_key_exists($unformattedAddress['country'],$this->countries))
        {
            throw new InvalidArgumentException($unformattedAddress['country'] . " is not a valid country.");
        }

        try {
            $validatedAddressResponse = $this->client->post($this->uri . "/api/v1/_data/validate_address", [
                'headers' => [
                    'Content-Type' => 'application/json; charset=UTF8',
                    'timeout' => 30,
                ],
                'auth' => [
                    $this->username,
                    $this->password,
                ],
                'json' => $unformattedAddress,
            ]);

            $address = (array)json_decode($validatedAddressResponse->getBody())->data;
            if ($unformattedAddress['latitude'] && $unformattedAddress['longitude'])
            {
                $address['latitude'] = $unformattedAddress['latitude'];
                $address['longitude'] = $unformattedAddress['longitude'];
            }
            return $address;
        }
        catch (Exception $e)
        {
            /**
             * The address failed to validate, but we will still attempt to validate individual parts of it to see if it can be used.
             */
            if (!array_key_exists($unformattedAddress['country'],$this->subDivisions))
            {
                $subDivisions = $this->client->get($this->uri . "/api/v1/_data/subdivisions/{$unformattedAddress['country']}", [
                    'headers' => [
                        'Content-Type' => 'application/json; charset=UTF8',
                        'timeout' => 30,
                    ],
                    'auth' => [
                        $this->username,
                        $this->password,
                    ],
                ]);

                $subDivisionObject = json_decode($subDivisions->getBody());
                $this->subDivisions[$unformattedAddress['country']] = (array)$subDivisionObject->data;
            }

            if (!array_key_exists($unformattedAddress['state'],$this->subDivisions[$unformattedAddress['country']]))
            {
                throw new InvalidArgumentException($unformattedAddress['state'] . " is not a valid subdivision for " . $unformattedAddress['country']);
            }

            if ($unformattedAddress['country'] == "US")
            {
                if (!$unformattedAddress['county'])
                {
                    throw new InvalidArgumentException("The address failed to validate, and a county is required for addresses in the US.");
                }

                if (!array_key_exists($unformattedAddress['state'],$this->counties))
                {
                    $counties = $this->client->get($this->uri . "/api/v1/_data/counties/{$unformattedAddress['state']}", [
                        'headers' => [
                            'Content-Type' => 'application/json; charset=UTF8',
                            'timeout' => 30,
                        ],
                        'auth' => [
                            $this->username,
                            $this->password,
                        ],
                    ]);

                    $countyArray = (array)json_decode($counties->getBody());
                    $this->counties[$unformattedAddress['state']] = $countyArray;
                }

                if (!in_array($unformattedAddress['county'],$this->counties[$unformattedAddress['state']]))
                {
                    throw new InvalidArgumentException("The county is not a valid county for the state.");
                }
            }

            return $unformattedAddress;
        }
    }
}