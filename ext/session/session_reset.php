<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_reset as StandardSessionReset;

/**
 * session_reset() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #6002).
 */
final class session_reset extends StandardSessionReset
{
}
