<?php
// Discarded phpversion/php_uname/getmypid/getmyuid/getmygid must match Zend (#36386).
// Side-effect-free statements only — results unused (soft-null coerce kept live elsewhere).
// php-src: ext/standard/info.c, ext/standard/basic_functions.c
// @differential-repeat: 3
function work(string $mode, string $ext, int $loops): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        phpversion();
        phpversion($ext);
        php_uname();
        php_uname($mode);
        getmypid();
        getmyuid();
        getmygid();
        $c += $k;
    }

    return $c
        + (is_string(phpversion()) ? 1 : 0)
        + (is_string(php_uname($mode)) ? 1 : 0)
        + (getmypid() > 0 ? 1 : 0)
        + (is_int(getmyuid()) ? 1 : 0)
        + (is_int(getmygid()) ? 1 : 0);
}
echo work('s', 'standard', 5), "\n";
echo work('n', 'Core', 3), "\n";
echo work('m', 'standard', 2), "\n";
