<?php
// @differential-skip-aot: TypeError message text; AOT covered by compliance + VM/JIT
function expect_iterable(): iterable
{
    return 1;
}
try {
    $r = expect_iterable();
    echo 'bad:returned=', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo 'ok:TypeError:', $e->getMessage(), "\n";
}
