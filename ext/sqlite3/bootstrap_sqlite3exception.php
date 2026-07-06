<?php

declare(strict_types=1);

/**
 * Global SQLite3Exception when ext/sqlite3 is not loaded on the host (php-src ext/sqlite3/sqlite3.c).
 */
if (!\class_exists(\SQLite3Exception::class, false)) {
    class SQLite3Exception extends \Exception
    {
    }
}
