<?php

namespace App\Controller;

use Airship\Webdav\Filesystem\Locator;
use Airship\Webdav\Lock\LockTender;
use Airship\Webdav\Lock\Keyring;
use Airship\Webdav\Property\Reader\CollectionReader;
use Airship\Webdav\Property\Reader\NonCollectionReader;
use Airship\Webdav\RequestHeaders;
use Airship\Webdav\RequestHeaderValues;
use Airship\Webdav\RequestMethods;
use Airship\Webdav\ResponseHeaders;
use Airship\Webdav\ResponseHeaderValues;
use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as Framework;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @todo Split this class.
 *
 * @link https://tech.yandex.com/disk/doc/dg/reference/propfind_contains-request-docpage/#propfind_contains-request
 */
class WebdavController
{
    /**
     * @var Locator
     */
    private $locator;

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

    /**
     * @var LockTender
     */
    private $lockTender;

    /**
     * @var CollectionReader
     */
    private $collectionReader;

    /**
     * @var NonCollectionReader
     */
    private $nonCollectionReader;

    public function __construct(Locator $locator, LoggerInterface $logger, \Twig_Environment $twig, Filesystem $filesystem, LockTender $lockTender, CollectionReader $collectionReader, NonCollectionReader $nonCollectionReader)
    {
        $this->locator = $locator;
        $this->logger = $logger;
        $this->twig = $twig;
        $this->filesystem = $filesystem;
        $this->lockTender = $lockTender;
        $this->collectionReader = $collectionReader;
        $this->nonCollectionReader = $nonCollectionReader;
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
            'Accepts'               => implode(' ', RequestMethods::COMPLIANCE_2_SUPPORTED)
        ]);
    }

    /**
     * @Framework\Route("/", methods={"PROPFIND"}, name="webdav_share_resource_index")
     *
     * @link https://tech.yandex.com/disk/doc/dg/reference/propfind_contains-request-docpage/#propfind_contains-request
     */
    public function indexAction(Request $request)
    {
        $doc = $this->collectionReader->asXmlDocument('/');

        return new Response($doc->saveXML(),Response::HTTP_MULTI_STATUS, ['Content-Type' => 'application/xml; charset="utf-8"']);
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

        $f = $this->locator->getFilesDir().$resourceRequestPath;

        if ($this->filesystem->exists($f)) {
            $info = new \SplFileInfo($f);

            if ($info->isDir()) {
                $doc = $this->collectionReader->asXmlDocument($resourceRequestPath);
            } else {
                $doc = $this->nonCollectionReader->asXmlDocument($resourceRequestPath);
            }

            return new Response($doc->saveXML(),Response::HTTP_MULTI_STATUS, ['Content-Type' => 'application/xml; charset="utf-8"']);
        }

        return new Response("{$f} does not exist. dir {$this->locator->getFilesDir()}", 404);
    }

    /**
     * @Framework\Route("/{resource}", methods={"GET", "HEAD"}, name="webdav_share_resource_get", requirements={"resource"=".+"})
     */
    public function resourceGetAction(Request $request): Response
    {
        $resource = $request->attributes->get('resource');
        $resourceRequestPath = '/'.$resource;

        if (false !== strpos($resource,  '/..')) {
            throw new \RuntimeException('Nice try.');
        }

        $f = $this->locator->getFilesDir().$resourceRequestPath;

        if (! $this->filesystem->exists($f)) {
            return new Response("{$resource} does not exist.", 404);
        }

        return new BinaryFileResponse(new \SplFileInfo($f));
    }

    /**
     * @Framework\Route("/{resource}", methods={"PUT"}, name="webdav_share_resource_put", requirements={"resource"=".+"})
     */
    public function resourcePutAction(Request $request): Response
    {
        $resource = $request->attributes->get('resource');
        $resourceRequestPath = '/'.$resource;
        $f = $this->locator->getFilesDir().$resourceRequestPath;

        if (false !== strpos($resource,  '/..')) {
            throw new \RuntimeException('Nice try.');
        }

        $ifHeaderValue = $request->headers->get(RequestHeaders::IF);
        preg_match('/\(\<(urn:uuid:.+)\>\)/', $ifHeaderValue, $matches);
        $lockTokenUrn = $matches[1] ?? null;

        $keyring = new Keyring($resource, $lockTokenUrn);

        $this->lockTender->enforce($keyring);

        $this->filesystem->dumpFile($f, $request->getContent());

        return new Response('', Response::HTTP_CREATED);
    }

    /**
     * @Framework\Route("/{resource}", methods={"DELETE"}, name="webdav_share_resource_delete", requirements={"resource"=".+"})
     */
    public function resourceDeleteAction(Request $request): Response
    {
        $resource = $request->attributes->get('resource');
        $resourceRequestPath = '/'.$resource;
        $f = $this->locator->getFilesDir().$resourceRequestPath;

        if (false !== strpos($resource,  '/..')) {
            throw new \RuntimeException('Nope.');
        }

        // @todo
        if (is_dir($f)) {
            return new Response('', Response::HTTP_NOT_IMPLEMENTED);
        }

        $ifHeaderValue = $request->headers->get(RequestHeaders::IF);
        preg_match('/\(\<(urn:uuid:.+)\>\)/', $ifHeaderValue, $matches);
        $lockTokenUrn = $matches[1] ?? null;

        $keyring = new Keyring($resource, $lockTokenUrn);

        $this->lockTender->enforce($keyring);

        $this->filesystem->remove($f);

        return new Response('', Response::HTTP_NO_CONTENT);
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
            'token_urn'    => $lockToken->getLockTokenUrn(),
            'resource_uri' => $resourceUri,
        ]);

        return new Response($xml, Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset="utf-8"',
            RequestHeaders::LOCK_TOKEN => $lockToken->getLockTokenUrnForHttpHeader(),
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

        $this->lockTender->unlock(new Keyring($resource, trim($lockTokenHeaderValue, '<>')));

        return new Response('', Response::HTTP_NO_CONTENT);
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

        $dir = "{$this->locator->getFilesDir()}/{$resource}";

        // @todo Don't make recursively, return HTTP 409 according to RFC.
        $this->filesystem->mkdir($dir);

        return new Response('', Response::HTTP_CREATED);
    }

    /**
     * Not used by macOS Finder it seems. When copying, it downloads the file first (GET), then it uploads it (PUT).
     *
     * 9.8.1.  COPY for Non-collection Resources
     * -----------------------------------------
     *
     *  When the source resource is not a collection, the result of the COPY
     *  method is the creation of a new resource at the destination whose
     *  state and behavior match that of the source resource as closely as
     *  possible.  Since the environment at the destination may be different
     *  than at the source due to factors outside the scope of control of the
     *  server, such as the absence of resources required for correct
     *  operation, it may not be possible to completely duplicate the
     *  behavior of the resource at the destination.  Subsequent alterations
     *  to the destination resource will not modify the source resource.
     *  Subsequent alterations to the source resource will not modify the
     *  destination resource.
     *
     *
     * 9.9.  MOVE Method
     * ------------------
     *
     *  The MOVE operation on a non-collection resource is the logical
     *  equivalent of a copy (COPY), followed by consistency maintenance
     *  processing, followed by a delete of the source, where all three
     *  actions are performed in a single operation.  The consistency
     *  maintenance step allows the server to perform updates caused by the
     *  move, such as updating all URLs, other than the Request-URI that
     *  identifies the source resource, to point to the new destination
     *  resource.
     *
     * @Framework\Route("/{resource}", methods={"COPY", "MOVE"}, name="webdav_share_resource_copy_or_move", requirements={"resource"=".+"})
     */
    public function copyOrMoveAction(Request $request): Response
    {
        $resource    = $request->attributes->get('resource');
        $destination = $request->headers->get(RequestHeaders::DESTINATION);
        $overwrite   = $request->headers->get(RequestHeaders::OVERWRITE, RequestHeaderValues::OVERWRITE_T);

        if (! $destination) {
            // @todo HTTP 400 Bad Request?
        }

        $uri = new Uri($destination);

        // @todo Locator needs to be aware of the base URL the controller is mounted to.
        $sourceFilePath      = $this->locator->resolveAbsolutePath($resource);
        $destinationFilePath = $this->locator->resolveAbsolutePath($uri->getPath());

        if ($sourceFilePath === $destinationFilePath) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $shouldNotOverwrite       = RequestHeaderValues::OVERWRITE_F === $overwrite;
        $destinationAlreadyExists = $this->filesystem->exists($destinationFilePath);
        $parentDoesNotExist       = !$destinationAlreadyExists && !$this->filesystem->exists(dirname($destinationFilePath));

        if ($shouldNotOverwrite && $destinationAlreadyExists) {
            return new Response('', Response::HTTP_PRECONDITION_FAILED);
        }

        if ($parentDoesNotExist) {
            return new Response('', Response::HTTP_CONFLICT);
        }

        // @todo HTTP 207 (Multi-Status)
        // @todo HTTP 423 (Locked)
        // @todo HTTP 502 (Bad Gateway)
        // @todo HTTP 507 (Insufficient Storage)

        if ($request->isMethod(RequestMethods::COPY)) {
            $this->filesystem->copy($sourceFilePath, $destinationFilePath, true);
        } elseif ($request->isMethod(RequestMethods::MOVE)) {
            $this->filesystem->rename($sourceFilePath, $destinationFilePath, true);
        }

        if ($destinationAlreadyExists) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        return new Response('', Response::HTTP_CREATED, [
            ResponseHeaders::LOCATION => $destination,
        ]);
    }

    /**
     * @todo
     *
     * @Framework\Route("/{resource}", methods={"POST"}, name="webdav_share_resource_post", requirements={"resource"=".+"})
     */
    public function postAction(Request $request): Response
    {
        return new Response('', Response::HTTP_METHOD_NOT_ALLOWED);
    }

    /**
     * @todo
     *
     * @Framework\Route("/{resource}", methods={"PROPPATCH"}, name="webdav_share_resource_proppatch", requirements={"resource"=".+"})
     */
    public function proppatchAction(Request $request): Response
    {
        return new Response('', Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
