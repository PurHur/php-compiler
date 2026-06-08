<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_status_ as StandardSessionStatus;

/**
 * session_status() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #7321).
 */
final class session_status extends StandardSessionStatus
{
}
