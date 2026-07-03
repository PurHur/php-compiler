<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_cache_limiter_ as StandardSessionCacheLimiter;

/**
 * session_cache_limiter() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #11095).
 */
final class session_cache_limiter extends StandardSessionCacheLimiter
{
}
