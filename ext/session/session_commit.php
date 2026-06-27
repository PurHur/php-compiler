<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_write_close as StandardSessionWriteClose;

/**
 * session_commit() — alias of session_write_close() (php-src ext/session/session.c; #12544).
 */
final class session_commit extends StandardSessionWriteClose
{
    public function __construct()
    {
        parent::__construct('session_commit');
    }
}
