<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_gc as StandardSessionGc;

/**
 * session_gc() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #6006).
 */
final class session_gc extends StandardSessionGc
{
}
