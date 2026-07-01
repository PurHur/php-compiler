<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_cache_expire_ as StandardSessionCacheExpire;

/**
 * session_cache_expire() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #14613).
 */
final class session_cache_expire extends StandardSessionCacheExpire
{
}
