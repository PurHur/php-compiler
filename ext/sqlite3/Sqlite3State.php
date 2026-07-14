<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

/** Per-instance sqlite3 handle state (php-src ext/sqlite3/sqlite3.c; issue #3434). */
final class Sqlite3State
{
    /** @var \FFI\CData|null sqlite3* */
    public ?\FFI\CData $db = null;

    public bool $closed = false;

    public string $filename = '';
}
