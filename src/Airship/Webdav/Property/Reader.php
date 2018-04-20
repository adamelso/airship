<?php

namespace Airship\Webdav\Property;

interface Reader
{
    /**
     * @param string $resourceRequestPath The path as it appears in the HTTP request.
     *
     * @return \DOMDocument
     */
    public function asXmlDocument(string $resourceRequestPath): \DOMDocument;
}
