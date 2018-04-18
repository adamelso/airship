<?php

namespace App\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;

class HttpListener implements EventSubscriberInterface
{
    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var string
     */
    private $projectDir;

    public function __construct(Filesystem $filesystem, string $projectDir)
    {
        $this->filesystem = $filesystem;
        $this->projectDir = $projectDir;
    }

    public function onKernelResponse(FilterResponseEvent $event)
    {
        $request  = $event->getRequest();
        $response = $event->getResponse();

        $t = (new \DateTime())->format('Y-m-d--H-i-s');
        $method = $request->getMethod();
        $code = $response->getStatusCode();

        $reqFilename = "{$this->projectDir}/var/http/{$t}--{$method}--{$code}-request.http";
        $resFilename = "{$this->projectDir}/var/http/{$t}--{$method}--{$code}-response.http";

        $this->filesystem->dumpFile($reqFilename, (string) $request);
        $this->filesystem->dumpFile($resFilename, (string) $response);
    }

    public static function getSubscribedEvents()
    {
        return [
            'kernel.response' => 'onKernelResponse',
        ];
    }
}
