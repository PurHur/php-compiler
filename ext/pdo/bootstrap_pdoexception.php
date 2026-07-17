<?php

declare(strict_types=1);

/**
 * Native PDOException for VM ThrowableManifest (#3367).
 * php-src: ext/pdo/pdo_dbh.c — PDOException extends RuntimeException.
 */
if (!\class_exists('PDOException', false)) {
    class PDOException extends RuntimeException
    {
        /** @var array|null */
        public $errorInfo = null;
    }
}
