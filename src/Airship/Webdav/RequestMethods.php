<?php

namespace Airship\Webdav;

interface RequestMethods
{
    const OPTIONS   = 'OPTIONS';
    const GET       = 'GET';
    const PROPFIND  = 'PROPFIND';
    const LOCK      = 'LOCK';
    const UNLOCK    = 'UNLOCK';
}
