<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\session_regenerate_id as StandardSessionRegenerateId;

/**
 * session_regenerate_id() — ext/session entry delegating to ext/standard (php-src ext/session/session.c; #6004).
 */
final class session_regenerate_id extends StandardSessionRegenerateId
{
}
