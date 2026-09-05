<?php
// #36382 — AOT preg_split with FastRoute (*SKIP)(*F)|\[ must match Zend (not false/Internal error).
$rx = <<<'REGEX'
\{
    \s* ([a-zA-Z_][a-zA-Z0-9_-]*) \s*
    (?:
        : \s* ([^{}]*(?:\{(?-1)\}[^{}]*)*)
    )?
\}
REGEX;
$pattern = '~' . $rx . '(*SKIP)(*F) | \[~x';
$segments = preg_split($pattern, '/hello');
if (false === $segments) {
    echo 'FAIL_SPLIT err=', preg_last_error(), ' ', preg_last_error_msg(), "\n";
    exit(1);
}
echo 'parts=', count($segments), ':', $segments[0], "\n";
$segments2 = preg_split($pattern, '/hello[/{id}]');
if (false === $segments2 || 2 !== count($segments2)) {
    echo 'FAIL_OPT ', var_export($segments2, true), "\n";
    exit(1);
}
echo 'opt=', $segments2[0], '|', $segments2[1], "\n";
echo "OK\n";
