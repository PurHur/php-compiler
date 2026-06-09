<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_encode as StandardSessionEncode;

/** session_encode() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #6086). */
final class session_encode extends StandardSessionEncode
{
}
