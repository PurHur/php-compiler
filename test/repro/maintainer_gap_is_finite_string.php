<?php
declare(strict_types=1);
try {
    var_export(is_finite('1.5'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
