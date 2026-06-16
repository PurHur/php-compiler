<?php
enum ES: string { case A = 'a'; }
enum Pure { case A; }

$x = ES::A;
echo 'backed debug_type=', get_debug_type($x), "\n";
echo 'backed is_object=', is_object($x) ? 'yes' : 'no', "\n";
echo 'backed serialize=', serialize($x), "\n";
try {
    str_contains($x, 'a');
    echo "backed str_contains: no TypeError\n";
} catch (TypeError $e) {
    echo 'backed str_contains: TypeError ', $e->getMessage(), "\n";
}

$p = Pure::A;
echo 'pure debug_type=', get_debug_type($p), "\n";
echo 'pure is_object=', is_object($p) ? 'yes' : 'no', "\n";
echo 'pure serialize=', serialize($p), "\n";
