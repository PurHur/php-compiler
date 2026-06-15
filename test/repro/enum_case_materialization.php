<?php
enum ES: string { case A = 'a'; }

$x = ES::A;
echo 'debug_type=', get_debug_type($x), "\n";
echo 'is_object=', is_object($x) ? 'yes' : 'no', "\n";
echo 'serialize=', serialize($x), "\n";
try {
    str_contains($x, 'a');
    echo "str_contains: no TypeError\n";
} catch (TypeError $e) {
    echo 'str_contains: TypeError ', $e->getMessage(), "\n";
}
