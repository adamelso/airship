<?php

namespace App\Controller;

use Airship\Webdav\Lock\LockTender;
use Airship\Webdav\Lock\LockToken;
use Airship\Webdav\RequestHeaders;
use Airship\Webdav\RequestMethods;
use Airship\Webdav\ResponseHeaders;
use Airship\Webdav\ResponseHeaderValues;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as Framework;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\Factory;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\StoreInterface;

/**
 * @todo Split this class.
 *
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
     * @var LockTender
     */
    private $lockTender;

    public function __construct(LoggerInterface $logger, \Twig_Environment $twig, Filesystem $filesystem, LockTender $lockTender, $projectDir)
    {
        $this->logger = $logger;
        $this->twig = $twig;
        $this->filesystem = $filesystem;
        $this->projectDir = $projectDir;
        $this->filesDir = $projectDir .'/var/files';
        $this->lockTender = $lockTender;
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

        $lockToken = $this->lockTender->lock($resource);

        $xml = $this->twig->render('lock.xml.twig', [
            'token_urn'    => $lockToken->getUrn(),
            'resource_uri' => $resourceUri,
        ]);

        return new Response($xml, Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset="utf-8"',
            RequestHeaders::LOCK_TOKEN => $lockToken->getUrnForHttpHeader(),
        ]);
    }

    /**
     * 9.11. UNLOCK Method
     *
     * The UNLOCK method removes the lock identified by the lock token in
     * the Lock-Token request header.  The Request-URI MUST identify a
     * resource within the scope of the lock.
     *
     * Note that use of the Lock-Token header to provide the lock token is
     * not consistent with other state-changing methods, which all require
     * an If header with the lock token.  Thus, the If header is not needed
     * to provide the lock token.  Naturally, when the If header is present,
     * it has its normal meaning as a conditional header.
     *
     * For a successful response to this method, the server MUST delete the
     * lock entirely.
     *
     * If all resources that have been locked under the submitted lock token
     * cannot be unlocked, then the UNLOCK request MUST fail.
     *
     * A successful response to an UNLOCK method does not mean that the
     * resource is necessarily unlocked.  It means that the specific lock
     * corresponding to the specified token no longer exists.
     *
     * Any DAV-compliant resource that supports the LOCK method MUST support
     * the UNLOCK method.
     *
     * This method is idempotent, but not safe (see Section 9.1 of
     * [RFC2616]).  Responses to this method MUST NOT be cached.
     *
     * @Framework\Route("/{resource}", methods={"UNLOCK"}, name="webdav_share_resource_unlock", requirements={"resource"=".+"})
     */
    public function unlockAction(Request $request): Response
    {
        $resource = $request->attributes->get('resource');
        $resourceUri = $request->getUri();

        $lockTokenHeaderValue = $request->headers->get(RequestHeaders::LOCK_TOKEN, null);

        if (! $lockTokenHeaderValue) {
            return new Response('No lock token was provided.', Response::HTTP_BAD_REQUEST);
        }

        $this->lockTender->unlock(new LockToken(trim($lockTokenHeaderValue, '<>'), $resource));

        return new Response('', Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset="utf-8"',
            RequestHeaders::LOCK_TOKEN => "<{$urn}>",
        ]);
    }

    /**
     * 9.3.  MKCOL Method
     *
     * MKCOL creates a new collection resource at the location specified by
     * the Request-URI.  If the Request-URI is already mapped to a resource,
     * then the MKCOL MUST fail.  During MKCOL processing, a server MUST
     * make the Request-URI an internal member of its parent collection,
     * unless the Request-URI is "/".  If no such ancestor exists, the
     * method MUST fail.  When the MKCOL operation creates a new collection
     * resource, all ancestors MUST already exist, or the method MUST fail
     * with a 409 (Conflict) status code.  For example, if a request to
     * create collection /a/b/c/d/ is made, and /a/b/c/ does not exist, the
     * request must fail.
     *
     * When MKCOL is invoked without a request body, the newly created
     * collection SHOULD have no members.
     *
     * A MKCOL request message may contain a message body.  The precise
     * behavior of a MKCOL request when the body is present is undefined,
     * but limited to creating collections, members of a collection, bodies
     * of members, and properties on the collections or members.  If the
     * server receives a MKCOL request entity type it does not support or
     * understand, it MUST respond with a 415 (Unsupported Media Type)
     * status code.  If the server decides to reject the request based on
     * the presence of an entity or the type of an entity, it should use the
     * 415 (Unsupported Media Type) status code.
     *
     * This method is idempotent, but not safe (see Section 9.1 of
     * [RFC2616]).  Responses to this method MUST NOT be cached.
     *
     * @Framework\Route("/{resource}", methods={"MKCOL"}, name="webdav_share_resource_mkcol", requirements={"resource"=".+"})
     */
    public function mkcolAction(Request $request): Response
    {
        $resource = $request->attributes->get('resource');

        $dir = "{$this->filesDir}/{$resource}";

        // @todo Don't make recursively, return HTTP 409 according to RFC.
        $this->filesystem->mkdir($dir);

        return new Response('', Response::HTTP_CREATED);
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
