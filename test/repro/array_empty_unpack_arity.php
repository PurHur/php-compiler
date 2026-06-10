<?php

declare(strict_types=1);

$a = [1];
echo array_unshift($a, ...[]), "\n";
var_export($a);
echo "\n";

var_export(array_merge(...[]));
echo "\n";

try {
    array_multisort(...[]);
    echo "multisort: uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
