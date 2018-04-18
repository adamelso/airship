<?php

namespace Airship\Webdav\Lock;

use Psr\Cache\CacheItemPoolInterface;
use Ramsey\Uuid\UuidFactoryInterface;
use Symfony\Component\Lock\Factory;

class LockTender
{
    /**
     * @var Factory
     */
    private $lockFactory;

    /**
     * @var UuidFactoryInterface
     */
    private $uuidFactory;

    /**
     * @var CacheItemPoolInterface
     */
    private $pool;

    public function __construct(Factory $lockFactory, UuidFactoryInterface $uuidFactory, CacheItemPoolInterface $pool)
    {
        $this->lockFactory = $lockFactory;
        $this->uuidFactory = $uuidFactory;
        $this->pool = $pool;
    }

    public function lock(string $resource): LockToken
    {
        $lock = $this->lockFactory->createLock($resource);

        $uuid = $this->uuidFactory->uuid4();
        $urn = $uuid->getUrn();

        $lockToken = new LockToken($urn, $resource);

        if ($lock->isAcquired()) {
            throw new \RuntimeException('Already locked.');
        }

        $lock->acquire();

        $padlock = $this->pool->getItem($lockToken->getUuid());
        $padlock->set($lockToken->getResource());

        $this->pool->save($padlock);

        return $lockToken;
    }

    public function unlock(LockToken $lockToken)
    {
        $padlock = $this->pool->getItem($lockToken->getUuid());

        if (! $padlock->isHit()) {
            throw new \RuntimeException('This Lock Token URN does not correspond to any lock.');
        }

        if ($lockToken->getResource() !== $padlock->get()) {
            throw new \RuntimeException('This Lock Token URN does not unlock the requested resource.');
        }

        $lock = $this->lockFactory->createLock($lockToken->getResource());

        $lock->release();

        $deleted = $this->pool->deleteItem($padlock->getKey());

        if (! $deleted) {
            throw new \RuntimeException('Lock was not deleted.');
        }
    }
}
