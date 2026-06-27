<?php

declare(strict_types=1);

// Repro for #12481 — set_time_limit / ini_set must update ini_get('max_execution_time').
$fail = 0;

if ('0' !== ini_get('max_execution_time')) {
    echo "fail: default ini_get expected 0, got ".ini_get('max_execution_time')."\n";
    ++$fail;
}

if (!set_time_limit(30)) {
    echo "fail: set_time_limit(30) returned false\n";
    ++$fail;
}
if ('30' !== ini_get('max_execution_time')) {
    echo "fail: after set_time_limit(30) expected 30, got ".ini_get('max_execution_time')."\n";
    ++$fail;
}

$prev = ini_set('max_execution_time', '45');
if (false === $prev) {
    echo "fail: ini_set(max_execution_time, 45) returned false\n";
    ++$fail;
}
if ('45' !== ini_get('max_execution_time')) {
    echo "fail: after ini_set expected 45, got ".ini_get('max_execution_time')."\n";
    ++$fail;
}

if (!set_time_limit(-1)) {
    echo "fail: set_time_limit(-1) returned false\n";
    ++$fail;
}
if ('-1' !== ini_get('max_execution_time')) {
    echo "fail: after set_time_limit(-1) expected -1, got ".ini_get('max_execution_time')."\n";
    ++$fail;
}

exit(0 === $fail ? 0 : 1);
