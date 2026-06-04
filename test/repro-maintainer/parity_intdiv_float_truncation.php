<?php
var_export(intdiv(5.0, 2));
echo "\n";
var_export(intdiv(-5.9, 2));
echo "\n";
try {
    var_export(intdiv(5.5, 0.0));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
