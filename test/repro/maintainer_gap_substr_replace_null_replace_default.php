<?php
// #18956 — default profile must coerce null $replace like Zend 8.2 (ext/standard/string.c).

$result = substr_replace('hello', null, 0);
echo "result=", var_export($result, true), "\n";
if ('' !== $result) {
    echo "fail: expected ''\n";
    exit(1);
}
echo "ok\n";
