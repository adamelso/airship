<?php

namespace Airship\Webdav;

interface RequestMethods
{
    const COMPLIANCE_2_SUPPORTED = [
        self::OPTIONS,
        self::HEAD,
        self::GET,
        self::PUT,
        self::POST,
        self::DELETE,
        self::PROPFIND,
        self::PROPPATCH,
        self::COPY,
        self::MOVE,
        self::LOCK,
        self::UNLOCK,
    ];

    const OPTIONS    = 'OPTIONS';
    const HEAD       = 'HEAD';
    const GET        = 'GET';
    const PUT        = 'PUT';
    const POST       = 'POST';
    const DELETE     = 'DELETE';
    const PROPFIND   = 'PROPFIND';
    const PROPPATCH  = 'PROPPATCH';
    const COPY       = 'COPY';
    const MOVE       = 'MOVE';
    const LOCK       = 'LOCK';
    const UNLOCK     = 'UNLOCK';
}
