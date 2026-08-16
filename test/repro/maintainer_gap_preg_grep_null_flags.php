<?php
/** preg_grep(..., null) $flags under strict_types */
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(preg_grep('/a/', ['a', 'b'], null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
