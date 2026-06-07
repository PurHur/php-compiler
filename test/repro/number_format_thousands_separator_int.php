<?php
$fn = static fn () => number_format(1234.5, 2, '.', 0);
try {
    $fn();
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
