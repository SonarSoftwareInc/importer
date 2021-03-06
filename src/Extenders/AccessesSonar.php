<?php

namespace SonarSoftware\Importer\Extenders;

abstract class AccessesSonar
{
    protected $uri;
    protected $username;
    protected $password;
    protected $client;

    /**
     * Importer constructor.
     */
    public function __construct()
    {
        $dotenv = new \Dotenv\Dotenv(getcwd());
        $dotenv->overload();
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

        if (!file_exists(getcwd() . "/log_output"))
        {
            mkdir(getcwd() . "/log_output");
        }
    }
}