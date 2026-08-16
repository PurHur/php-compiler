<?php

declare(strict_types=1);

try {
    print_r([1], null);
    echo "fail print_r\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_export(1, null);
    echo "fail var_export\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
