<?php

namespace SonarSoftware\Importer;

use Exception;
use InvalidArgumentException;
use GuzzleHttp\Exception\ClientException;
use Extenders\AccessesSonar;

class AddressFormatter extends AccessesSonar
{
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
        parent::__construct();
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
     * @param bool $validate - Whether or not to try to convert the address to a mappable format. Should be set to false if you don't want the potential of the address being modified.
     * @return array
     */
    public function formatAddress($unformattedAddress, $validate = true)
    {
        if (!array_key_exists($unformattedAddress['country'],$this->countries))
        {
            throw new InvalidArgumentException($unformattedAddress['country'] . " is not a valid country.");
        }

        if ($validate === true)
        {
            //Remove the county from unformatted address so we can correct it if it's off
            $unformattedAddressMinusCounty = $unformattedAddress;
            if (array_key_exists("county",$unformattedAddressMinusCounty))
            {
                unset($unformattedAddressMinusCounty['county']);
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
                    'json' => $unformattedAddressMinusCounty,
                ]);

                $address = (array)json_decode($validatedAddressResponse->getBody())->data;
                if (array_key_exists("latitude",$unformattedAddress) && array_key_exists("longitude",$unformattedAddress))
                {

                    $address['latitude'] = trim($unformattedAddress['latitude']) != '' ? $unformattedAddress['latitude'] : $address['latitude'];
                    $address['longitude'] = trim($unformattedAddress['longitude']) != '' ? $unformattedAddress['longitude'] : $address['longitude'];
                }
                return $address;
            }
            catch (Exception $e)
            {
                return $this->doChecksOnUnvalidatedAddress($unformattedAddress);
            }
        }
        else
        {
            return $this->doChecksOnUnvalidatedAddress($unformattedAddress);
        }
    }

    /**
     * @param $unformattedAddress
     * @return mixed
     */
    private function doChecksOnUnvalidatedAddress($unformattedAddress)
    {
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

        if (!array_key_exists(trim($unformattedAddress['state']),$this->subDivisions[$unformattedAddress['country']]))
        {
            throw new InvalidArgumentException($unformattedAddress['state'] . " is not a valid subdivision for " . $unformattedAddress['country']);
        }

        if (trim($unformattedAddress['country']) == "US")
        {
            if (!array_key_exists(trim($unformattedAddress['state']),$this->counties))
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

                $countiesObject = json_decode($counties->getBody());
                $this->counties[$unformattedAddress['state']] = (array)$countiesObject->data;
            }

            if (count($this->counties[$unformattedAddress['state']]) > 0)
            {
                if (!in_array($unformattedAddress['county'],$this->counties[$unformattedAddress['state']]))
                {
                    throw new InvalidArgumentException("The county is not a valid county for the state.");
                }
            }
        }

        return $unformattedAddress;
    }
}