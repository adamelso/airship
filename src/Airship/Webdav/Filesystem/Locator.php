<?php

namespace Airship\Webdav\Filesystem;

class Locator
{
    /**
     * @var string
     */
    private $filesDir;

    public function __construct(string $filesDir)
    {
        $this->filesDir = $filesDir;
    }

    /**
     * @return string
     */
    public function getFilesDir(): string
    {
        return $this->filesDir;
    }
}
