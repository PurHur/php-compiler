<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_name as StandardSessionName;

/**
 * session_name() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #6004).
 */
final class session_name extends StandardSessionName
{
}
