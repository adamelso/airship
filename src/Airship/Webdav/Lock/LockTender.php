<?php

namespace Airship\Webdav\Lock;

use Psr\Cache\CacheItemPoolInterface;
use Ramsey\Uuid\UuidFactoryInterface;
use Symfony\Component\Lock\Factory;

/**
 * @todo Refresh method.
 */
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

    public function lock(string $resource): Keyring
    {
        $lock = $this->lockFactory->createLock($resource);

        $uuid = $this->uuidFactory->uuid4();
        $urn = $uuid->getUrn();

        $keyring = new Keyring($resource, $urn);

        if ($lock->isAcquired()) {
            throw new \RuntimeException('Already locked.');
        }

        $lock->acquire();

        $lockpin = $this->pool->getItem($keyring->getLockTokenUuid());
        $lockpin->set($keyring->getResource());

        $this->pool->save($lockpin);

        return $keyring;
    }

    public function unlock(Keyring $keyring)
    {
        $lockpin = $this->pool->getItem($keyring->getLockTokenUuid());

        if (! $lockpin->isHit()) {
            throw new \RuntimeException('The Lock Token URN does not correspond to any locked resource.');
        }

        if ($keyring->getResource() !== $lockpin->get()) {
            throw new \RuntimeException('The Lock Token URN does not unlock the requested resource.');
        }

        $lock = $this->lockFactory->createLock($keyring->getResource());

        $lock->release();

        $deleted = $this->pool->deleteItem($lockpin->getKey());

        if (! $deleted) {
            throw new \RuntimeException('Lock was not deleted.');
        }
    }

    public function breakLock(Keyring $keyring)
    {
        $lockpin = $this->pool->getItem($keyring->getLockTokenUuid());
        $lock = $this->lockFactory->createLock($keyring->getResource());
        $lock->release();

        $this->pool->deleteItem($lockpin->getKey());
    }

    public function enforce(Keyring $keyring)
    {
        $lock = $this->lockFactory->createLock($keyring->getResource());

        if ($lock->isAcquired() && $lock->isExpired()) {
            $this->breakLock($keyring);
        }

        if (! $lock->isAcquired()) {
            return;
        }

        if (! $keyring->getLockTokenUuid()) {
            throw new \RuntimeException('Cannot attempt to access a locked resource without a lock token.');
        }

        $lockpin = $this->pool->getItem($keyring->getLockTokenUuid());

        if (! $lockpin->isHit()) {
            throw new \RuntimeException('The Lock Token URN does not correspond to any locked resource.');
        }

        if ($keyring->getResource() !== $lockpin->get()) {
            throw new \RuntimeException('The Lock Token URN does not unlock the requested resource.');
        }
    }
}
