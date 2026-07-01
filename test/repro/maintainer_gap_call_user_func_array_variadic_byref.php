<?php
declare(strict_types=1);

function bump_first(&...$args): void
{
    if (count($args) > 0) {
        $args[0] = 99;
    }
}

$x = 1;
call_user_func_array('bump_first', [&$x]);
if ($x !== 99) {
    fwrite(STDERR, "FAIL: call_user_func_array expected 99 got {$x}\n");
    exit(1);
}

echo "ok\n";
