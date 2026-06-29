<?php

$fail = 0;

foreach (['is_link', 'is_readable', 'is_writable', 'is_executable'] as $fn) {
    try {
        $result = @$fn(null);
    } catch (\TypeError $e) {
        echo "fail: {$fn}(null) TypeError — ", $e->getMessage(), "\n";
        ++$fail;
        continue;
    }
    if (false !== $result) {
        echo "fail: {$fn}(null) expected false, got ", var_export($result, true), "\n";
        ++$fail;
    }
}

echo 0 === $fail ? "ok\n" : "fail\n";
exit($fail === 0 ? 0 : 1);
