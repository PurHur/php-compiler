<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_destroy as StandardSessionDestroy;

/**
 * session_destroy() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #6004).
 */
final class session_destroy extends StandardSessionDestroy
{
}
