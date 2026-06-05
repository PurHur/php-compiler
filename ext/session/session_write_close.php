<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_write_close as StandardSessionWriteClose;

/**
 * session_write_close() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #6004).
 */
final class session_write_close extends StandardSessionWriteClose
{
}
