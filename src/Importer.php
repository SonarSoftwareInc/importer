<?php

namespace SonarSoftware\Importer;


class Importer
{
    private $uri;
    private $username;
    private $password;

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
}