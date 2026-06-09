<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_decode as StandardSessionDecode;

/** session_decode() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #6086). */
final class session_decode extends StandardSessionDecode
{
}
