<?php

namespace App;


class FtpClient
{
    private $connection;
    private $login;

    public function __construct($host, $username, $password)
    {
        $this->connection = ftp_connect($host);
        $this->login = ftp_login($this->connection, $username, $password);
    }

    public function downloadFile($remoteFile, $localFile)
    {
        if ($this->login) {
            return ftp_get($this->connection, $localFile, $remoteFile, FTP_BINARY);
        }
        return false;
    }

    public function uploadFile($localFile, $remoteFile)
    {
        if ($this->login) {
            return ftp_put($this->connection, $remoteFile, $localFile, FTP_BINARY);
        }
        return false;
    }

    public function listFiles($directory)
    {
        if ($this->login) {
            return ftp_nlist($this->connection, $directory);
        }
        return [];
    }

    public function __destruct()
    {
        if ($this->connection) {
            ftp_close($this->connection);
        }
    }
}