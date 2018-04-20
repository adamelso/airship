<?php

namespace Airship\Webdav\Property;

class Href
{
    /**
     * @var string
     */
    private $resourcePath;

    public function __construct(string $resourcePath)
    {
        $this->resourcePath = $resourcePath;
    }

    public function __toString()
    {
        $segments = explode('/', $this->resourcePath);

        return implode('/', array_map('rawurlencode', $segments));
    }
}
