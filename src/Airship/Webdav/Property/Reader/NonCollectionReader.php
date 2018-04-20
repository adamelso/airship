<?php

namespace Airship\Webdav\Property\Reader;

use Airship\Webdav\Property\Href;
use Airship\Webdav\Property\Reader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;

/**
 * For Non-Collections (aka files).
 */
class NonCollectionReader implements Reader
{
    /**
     * @var string
     */
    private $filesDir;

    /**
     * @var \Twig_Environment
     */
    private $twig;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param string $filesDir
     * @param \Twig_Environment $twig
     * @param Filesystem $filesystem
     */
    public function __construct(string $filesDir, \Twig_Environment $twig, Filesystem $filesystem)
    {
        $this->filesDir = $filesDir;
        $this->twig = $twig;
        $this->filesystem = $filesystem;
    }

    /**
     * @param string $resourceRequestPath
     *
     * @return \DOMDocument
     */
    public function asXmlDocument(string $resourceRequestPath): \DOMDocument
    {
        $file = $this->filesDir.'/'.ltrim($resourceRequestPath, '/');
        $fileInfo = new \SplFileInfo($file);

        $f = [
            'href' => (string) new Href($this->filesystem->makePathRelative($file, $this->filesDir)),
            'lastModified' => $fileInfo->getMTime(),
            'contentLength' => $fileInfo->isDir() ? 0 : $fileInfo->getSize(),
            'creationDate' => $fileInfo->getCTime(),
            'resourceType' => $fileInfo->isDir()
                ? 'collection'
                : MimeTypeGuesser::getInstance()->guess($fileInfo->getPathname()),
        ];

        $xmlIn = $this->twig->render('propfind-file.xml.twig', [
            'file' => $f,
        ]);

        $doc = new \DOMDocument();
        $doc->loadXML($xmlIn);

        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        return $doc;
    }
}
