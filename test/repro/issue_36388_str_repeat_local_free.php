<?php
function local_free(int $n): void {
    $u0 = memory_get_usage(false);
    $s = str_repeat("x", $n);
    $u1 = memory_get_usage(false);
    unset($s);
    $u2 = memory_get_usage(false);
    echo "local d1=", ($u1-$u0), " left=", ($u2-$u0), " freed=", ($u2<$u1?"y":"n"), "\n";
}
function concat_free(int $n): void {
    $u0 = memory_get_usage(false);
    $s = "";
    for ($i = 0; $i < $n; $i++) $s .= "x";
    $u1 = memory_get_usage(false);
    unset($s);
    $u2 = memory_get_usage(false);
    echo "concat d1=", ($u1-$u0), " left=", ($u2-$u0), " freed=", ($u2<$u1?"y":"n"), "\n";
}
local_free(4000);
concat_free(4000);
