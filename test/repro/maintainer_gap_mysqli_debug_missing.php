<?php
/**
 * Repro #22223 — mysqli_dump_debug_info / mysqli_debug missing.
 * php-src: ext/mysqli/mysqli.stub.php + mysqli.c
 */
declare(strict_types=1);

echo 'mysqli_dump_debug_info=', function_exists('mysqli_dump_debug_info') ? 'yes' : 'NO', "\n";
echo 'mysqli_debug=', function_exists('mysqli_debug') ? 'yes' : 'NO', "\n";
echo 'method=', method_exists('mysqli', 'dump_debug_info') ? 'yes' : 'NO', "\n";

try {
    mysqli_dump_debug_info();
    echo "arity_dump=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_dump=yes\n";
}
try {
    mysqli_dump_debug_info(false);
    echo "type_dump=no\n";
} catch (TypeError $e) {
    echo "type_dump=yes\n";
}
try {
    mysqli_debug();
    echo "arity_debug=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_debug=yes\n";
}

$ok = mysqli_debug('d:t:o,/dev/null');
echo 'debug_ret=', ($ok === true) ? 'true' : 'other', "\n";
