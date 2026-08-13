<?php

declare(strict_types=1);

/**
 * #29976 — number_format(null) TypeError cites int|float (php-src basic_functions.stub.php).
 */
try {
    number_format(null);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
