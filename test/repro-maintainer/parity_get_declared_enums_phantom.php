<?php
// Maintainer repro (#11248): get_declared_enums() must not exist (php-src parity).
$fail = 0;
if (function_exists('get_declared_enums')) {
    echo "FAIL function_exists(get_declared_enums) is true\n";
    ++$fail;
}
enum UserEnum { case A; }
$classes = get_declared_classes();
if (!in_array('UserEnum', $classes, true)) {
    echo "FAIL UserEnum not in get_declared_classes()\n";
    ++$fail;
}
if (0 !== $fail) {
    exit(1);
}
echo "function absent\n";
