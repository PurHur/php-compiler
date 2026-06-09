<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_create_id as StandardSessionCreateId;

/**
 * session_create_id() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #6002).
 */
final class session_create_id extends StandardSessionCreateId
{
}
