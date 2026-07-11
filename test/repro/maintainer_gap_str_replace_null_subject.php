<?php

$s = str_replace('a', 'b', null);
if ('' !== $s) {
    echo "fail: str_replace(null subject) expected '' got ", var_export($s, true), "\n";
    exit(1);
}
$p = preg_replace('/a/', 'b', null);
if ('' !== $p) {
    echo "fail: preg_replace(null subject) expected '' got ", var_export($p, true), "\n";
    exit(1);
}
echo "ok\n";
