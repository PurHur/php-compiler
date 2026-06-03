<?php
var_export(intdiv('12', '3'));
echo "\n";
try {
    intdiv('x', 1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
