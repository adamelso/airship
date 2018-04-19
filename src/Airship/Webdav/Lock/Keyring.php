<?php

namespace Airship\Webdav\Lock;

class Keyring
{
    /**
     * @var string
     */
    private $resource;

    /**
     * @var string
     */
    private $urn;

    public function __construct(string $resource, string $urn = null)
    {
        $this->resource = $resource;
        $this->urn = $urn;
    }

    /**
     * @return null|string
     */
    public function getLockTokenUrn(): ?string
    {
        return $this->urn;
    }

    /**
     * @return string
     */
    public function getLockTokenUrnForHttpHeader(): string
    {
        if (! $this->urn) {
            throw new \LogicException('This Key Ring does not hold the lock token.');
        }

        return "<{$this->urn}>";
    }

    /**
     * @return null|string
     */
    public function getLockTokenUuid(): ?string
    {
        if (! $this->urn) {
            return null;
        }

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
