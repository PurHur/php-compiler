<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_module_name as StandardSessionModuleName;

/**
 * session_module_name() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #5749).
 */
final class session_module_name extends StandardSessionModuleName
{
}
