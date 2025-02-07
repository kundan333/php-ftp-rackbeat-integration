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

    public function copyRemoteFile($sourceFile, $destinationFile)
    {
        if ($this->login) {
            // Get file contents from the source file
            $data = $this->sftp->get($sourceFile);
            if ($data === false) {
                error_log("Failed to read source file: " . $sourceFile);
                return false;
            }

            // Write the data to the destination file
            $result = $this->sftp->put($destinationFile, $data);
            if (!$result) {
                error_log("Failed to write destination file: " . $destinationFile);
                return false;
            }

            return true;
        }

        return false;
    }

    public function __destruct()
    {
        $this->sftp->disconnect();
    }
}