<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_set_cookie_params_ as StandardSessionSetCookieParams;

/**
 * session_set_cookie_params() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #9982).
 */
final class session_set_cookie_params extends StandardSessionSetCookieParams
{
}
