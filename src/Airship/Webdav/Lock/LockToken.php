<?php

namespace Airship\Webdav\Lock;

use Ramsey\Uuid\Uuid;

class LockToken
{
    /**
     * @var string
     */
    private $urn;

    /**
     * @var string
     */
    private $resource;

    public function __construct(string $urn, string $resource)
    {
        $this->urn = $urn;
        $this->resource = $resource;
    }

    /**
     * @return string
     */
    public function getUrn(): string
    {
        return $this->urn;
    }

    /**
     * @return string
     */
    public function getUrnForHttpHeader(): string
    {
        return "<{$this->urn}>";
    }

    /**
     * @return string
     */
    public function getUuid(): string
    {
        return str_replace('urn:uuid:', '', $this->urn);
    }

    /**
     * @return string
     */
    public function getResource(): string
    {
        return $this->resource;
    }
}
