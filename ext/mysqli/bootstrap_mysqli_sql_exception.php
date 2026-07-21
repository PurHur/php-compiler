<?php

declare(strict_types=1);

/**
 * Native mysqli_sql_exception fallback for VM host bridges (#21803).
 *
 * php-src: ext/mysqli/mysqli_exception.c — mysqli_sql_exception extends RuntimeException.
 */
if (!\class_exists('mysqli_sql_exception', false)) {
    class mysqli_sql_exception extends RuntimeException
    {
    }
}
