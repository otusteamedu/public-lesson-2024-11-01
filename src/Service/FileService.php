<?php

namespace App\Service;

class FileService
{
    public const BACKGROUND_PID_FILE = 'background.pid';
    public const FOREGROUND_PID_FILE = 'foreground.pid';
    public const LOGIN_FILE = 'login.txt';

    private const WAIT_FOR_FILE_INTERVAL_MICROSECONDS = 100_000;

    public function writePidFile(string $filename): void
    {
        $this->writeFile($filename, getmypid());
    }

    public function writeFile(string $filename, string $contents): void
    {
        file_put_contents($filename, $contents);
    }

    public function removeFile(string $filename): void
    {
        unlink($filename);
    }

    public function getFileContentsWhenAppears(string $filename): string
    {
        while (!file_exists($filename)) {
            usleep(self::WAIT_FOR_FILE_INTERVAL_MICROSECONDS);
        }
        return file_get_contents($filename);
    }
}
