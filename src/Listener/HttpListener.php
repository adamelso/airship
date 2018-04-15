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

    private $t;

    public function __construct(Filesystem $filesystem, string $projectDir)
    {
        $this->filesystem = $filesystem;
        $this->projectDir = $projectDir;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        $this->t = time();
        $this->filesystem->dumpFile($this->projectDir.'/var/http/'.$this->t.'.request.http', (string) $event->getRequest());
    }

    public function onKernelResponse(FilterResponseEvent $event)
    {
        $this->filesystem->dumpFile($this->projectDir.'/var/http/'.$this->t.'.response.http', (string) $event->getResponse());

    }

    public static function getSubscribedEvents()
    {
        return [
            'kernel.request' => 'onKernelRequest',
            'kernel.response' => 'onKernelResponse',
        ];
    }
}
