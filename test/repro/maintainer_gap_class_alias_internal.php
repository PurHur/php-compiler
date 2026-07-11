<?php
declare(strict_types=1);

try {
    class_alias('stdClass', 'SC2');
    echo "alias ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_export(class_exists('SC2', false));
echo "\n";
