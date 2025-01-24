<?php

namespace App;

use phpseclib3\Net\SFTP;

class SftpClient
{
    private $sftp;
    private $login;

    public function __construct($host, $port, $username, $password)
    {
        $this->sftp = new SFTP($host, $port);
        $this->login = $this->sftp->login($username, $password);
    }

    public function downloadFile($remoteFile, $localFile)
    {
        if ($this->login) {
            return $this->sftp->get($remoteFile, $localFile);
        }
        return false;
    }

    public function uploadFile($localFile, $remoteFile)
    {
        if ($this->login) {
            return $this->sftp->put($remoteFile, $localFile, SFTP::SOURCE_LOCAL_FILE);
        }
        return false;
    }

    public function listFiles($directory)
    {
        if ($this->login) {
            return $this->sftp->nlist($directory);
        }
        return [];
    }

    public function downloadAllOrders($remoteDirectory, $localDirectory)
    {
        if ($this->login) {
            $files = $this->listFiles($remoteDirectory);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $remoteFilePath = $remoteDirectory . '/' . $file;
                    $localFilePath = $localDirectory . '/' . $file;
                    $this->downloadFile($remoteFilePath, $localFilePath);
                }
            }
        }
    }

    public function __destruct()
    {
        $this->sftp->disconnect();
    }
}