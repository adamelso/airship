<?php

namespace spec\Airship\Webdav\Lock;

use Airship\Webdav\Lock\LockToken;
use Airship\Webdav\Lock\LockTender;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class LockTenderSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->shouldHaveType(LockTender::class);
    }

    function it_locks_resources(LockToken $key)
    {
        $this->lock('hello.txt')->shouldReturn($key);
    }
}
