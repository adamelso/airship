<?php

namespace spec\Airship\Webdav\Lock;

use Airship\Webdav\Lock\Keyring;
use Airship\Webdav\Lock\LockTender;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class LockTenderSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->shouldHaveType(LockTender::class);
    }

    function it_locks_resources(Keyring $key)
    {
        $this->lock('hello.txt')->shouldReturn($key);
    }
}
