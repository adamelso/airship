<?php

namespace Airship\Webdav;

interface RequestHeaders
{
    const LOCK_TOKEN = 'Lock-Token';
    const IF = 'If';
    const DESTINATION = 'Destination';

    /**
     * 10.6.  Overwrite Header
     *
     *  Overwrite = "Overwrite" ":" ("T" | "F")
     *
     *  The Overwrite request header specifies whether the server should
     *  overwrite a resource mapped to the destination URL during a COPY or
     *  MOVE.  A value of "F" states that the server must not perform the
     *  COPY or MOVE operation if the destination URL does map to a resource.
     *  If the overwrite header is not included in a COPY or MOVE request,
     *  then the resource MUST treat the request as if it has an overwrite
     *  header of value "T".  While the Overwrite header appears to duplicate
     *  the functionality of using an "If-Match: *" header (see [RFC2616]),
     *  If-Match applies only to the Request-URI, and not to the Destination
     *  of a COPY or MOVE.
     *
     *  If a COPY or MOVE is not performed due to the value of the Overwrite
     *  header, the method MUST fail with a 412 (Precondition Failed) status
     *  code.  The server MUST do authorization checks before checking this
     *  or any conditional header.
     *
     *  All DAV-compliant resources MUST support the Overwrite header.
     */
    const OVERWRITE = 'Overwrite';
}
