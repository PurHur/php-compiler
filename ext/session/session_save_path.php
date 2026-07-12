<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_save_path_ as StandardSessionSavePath;

/**
 * session_save_path() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #3418).
 */
final class session_save_path extends StandardSessionSavePath
{
}
