<?php

namespace App\Controller;

use App\Airship\Webdav\RequestMethods;
use App\Airship\Webdav\ResponseHeaders;
use App\Airship\Webdav\ResponseHeaderValues;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as Framework;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\Factory;

/**
 * @link https://tech.yandex.com/disk/doc/dg/reference/propfind_contains-request-docpage/#propfind_contains-request
 */
class WebdavController
{
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var \Twig_Environment
     */
    private $twig;
    /**
     * @var Filesystem
     */
    private $filesystem;
    private $projectDir;
    private $filesDir;
    /**
     * @var Factory
     */
    private $lockFactory;
    /**
     * @var CacheItemPoolInterface
     */
    private $pool;

    public function __construct(LoggerInterface $logger, \Twig_Environment $twig, Filesystem $filesystem, Factory $lockFactory, CacheItemPoolInterface $pool, $projectDir)
    {
        $this->logger = $logger;
        $this->twig = $twig;
        $this->filesystem = $filesystem;
        $this->projectDir = $projectDir;
        $this->filesDir = $projectDir .'/var/files';
        $this->lockFactory = $lockFactory;
        $this->pool = $pool;
    }

    /**
     * @Framework\Route("/", methods={"OPTIONS"}, name="webdav_share_options_index")
     * @Framework\Route("/{resource}", methods={"OPTIONS"}, name="webdav_share_options", requirements={"resource"=".+"})
     */
    public function optionsAction()
    {
        return new Response('', Response::HTTP_OK, [
            ResponseHeaders::DAV    => ResponseHeaderValues::COMPLIANCE_CLASS_2,
            'Content-Length'        => 0,
            'Accepts'               => implode(' ', [
                RequestMethods::OPTIONS,
                RequestMethods::GET,
                RequestMethods::PROPFIND,
                RequestMethods::LOCK,
                RequestMethods::UNLOCK,
            ])
        ]);
    }

    /**
     * @Framework\Route("/", methods={"PROPFIND"}, name="webdav_share_resource_index")
     *
     * @link https://tech.yandex.com/disk/doc/dg/reference/propfind_contains-request-docpage/#propfind_contains-request
     */
    public function indexAction(Request $request)
    {
        $doc = $this->getPropertiesForDirectory('/');

        return new Response($doc->saveXML(),Response::HTTP_MULTI_STATUS, ['Content-Type' => 'application/xml']);
    }

    /**
     * @Framework\Route("/{resource}", methods={"PROPFIND"}, name="webdav_share_resource_propfind", requirements={"resource"=".+"})
     */
    public function resourcePropfindAction(Request $request)
    {
        $resource = $request->attributes->get('resource');
        $resourceRequestPath = '/'.$resource;

        if (false !== strpos($resource,  '/..')) {
            throw new \RuntimeException('Nice try.');
        }

        $f = $this->filesDir.$resourceRequestPath;

        if ($this->filesystem->exists($f)) {
            $info = new \SplFileInfo($f);

            if ($info->isDir()) {
                $doc = $this->getPropertiesForDirectory($resourceRequestPath);
            } else {
                $doc = $this->getPropertiesForFile($resourceRequestPath);
            }

            return new Response($doc->saveXML(),Response::HTTP_MULTI_STATUS, ['Content-Type' => 'application/xml']);
        }

        return new Response("{$resource} does not exist.", 404);
    }

    /**
     * @Framework\Route("/{resource}", methods={"GET"}, name="webdav_share_resource_get", requirements={"resource"=".+"})
     */
    public function resourceGetAction(Request $request)
    {
        $resource = $request->attributes->get('resource');
        $resourceRequestPath = '/'.$resource;

        if (false !== strpos($resource,  '/..')) {
            throw new \RuntimeException('Nice try.');
        }

        $f = $this->filesDir.$resourceRequestPath;

        if ($this->filesystem->exists($f)) {
            return new BinaryFileResponse(new \SplFileInfo($f));
        }

        return new Response("{$resource} does not exist.", 404);
    }

    /**
     * @Framework\Route("/{resource}", methods={"LOCK"}, name="webdav_share_resource_lock", requirements={"resource"=".+"})
     */
    public function lockAction(Request $request)
    {
        $resource = $request->attributes->get('resource');
        $resourceUri = $request->getUri();

        $requestDoc = new \DOMDocument();
        $requestDoc->loadXML($request->getContent());

        //$this->lockFactory->setLogger($this->logger);
        $lock = $this->lockFactory->createLock($resource);
        $lock->acquire();

        $lockTokenUuid = Uuid::uuid4();
        $urn = $lockTokenUuid->getUrn();

        $resourceLockRecord = $this->pool->getItem($resource);
        $resourceLockRecord->set($urn);
        $this->pool->save($resourceLockRecord);

        $xml = $this->twig->render('lock.xml.twig', [
            'token_urn'    => $urn,
            'resource_uri' => $resourceUri,
        ]);

        return new Response($xml, Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset="utf-8"',
            'Lock-Token' => "<{$urn}>",
        ]);
    }

    private function getPropertiesForFile(string $requestPath): \DOMDocument
    {
        $file = $this->filesDir.$requestPath;
        $fileInfo = new \SplFileInfo($file);

        $f = [
            'href' => $this->filesystem->makePathRelative($file, $this->filesDir),
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

    /**
     * @return \DOMDocument
     */
    private function getPropertiesForDirectory(string $requestPath): \DOMDocument
    {
        $dir = $this->filesDir.$requestPath;
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
