<?php

namespace Airship\Webdav\Property\Reader;

use Airship\Webdav\Property\Reader;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;

/**
 * For Collections (aka directories).
 */
class CollectionReader implements Reader
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
     * @param string $filesDir
     * @param \Twig_Environment $twig
     */
    public function __construct(string $filesDir, \Twig_Environment $twig)
    {
        $this->filesDir = $filesDir;
        $this->twig = $twig;
    }

    /**
     * @param string $resourceRequestPath
     *
     * @return \DOMDocument
     */
    public function asXmlDocument(string $resourceRequestPath): \DOMDocument
    {
        $dir = $this->filesDir.'/'.ltrim($resourceRequestPath, '/');
        $dirInfo = new \SplFileInfo($dir);

        $finder = new Finder();

        $finder->in($dir)->depth(0);

        $root = [
            'href' => '/',
            'lastModified' => $dirInfo->getMTime(),
            'contentLength' => $dirInfo->getSize(),
            'creationDate' => $dirInfo->getCTime(),
        ];
        $directory = [];

        foreach ($finder as $item) {
            $directory[] = [
                'href' => '/' . $item->getRelativePathname(),
                'lastModified' => $item->getMTime(),
                'contentLength' => $item->isDir() ? 0 : $item->getSize(),
                'creationDate' => $item->getCTime(),
                'resourceType' => $item->isDir()
                    ? 'collection'
                    : MimeTypeGuesser::getInstance()->guess($item->getPathname()),
            ];
        }

        $xmlIn = $this->twig->render('propfind-collection.xml.twig', [
            'collection' => $root,
            'directory' => $directory,
        ]);

        $doc = new \DOMDocument();
        $doc->loadXML($xmlIn);

        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        return $doc;
    }
}
