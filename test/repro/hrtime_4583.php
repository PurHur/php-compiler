<?php
try {
    hrtime(1);
    echo "int_ok\n";
} catch (TypeError $e) {
    echo "int_type_error\n";
}
$pair = hrtime();
$ns = hrtime(true);
echo is_array($pair) ? "pair\n" : "bad\n";
echo count($pair) === 2 ? "count2\n" : "bad\n";
echo is_int($ns) && $ns > 0 ? "ns\n" : "bad\n";
