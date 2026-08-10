<?php
// #29860 — weak bool param coerces non-empty string like Zend convert_to_boolean
function f(bool $x): bool
{
    return $x;
}

try {
    $got = f('x');
    echo $got ? "ok:bool-weak\n" : "fail:false\n";
} catch (Throwable $e) {
    echo 'fail:throw:'.get_class($e).': '.$e->getMessage()."\n";
}
