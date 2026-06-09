<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_unset as StandardSessionUnset;

/** session_unset() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; pairs #6261). */
final class session_unset extends StandardSessionUnset
{
}
