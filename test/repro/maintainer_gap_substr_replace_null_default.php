<?php
// #18913 — default profile must coerce null subject like Zend 8.2 (ext/standard/string.c).

$result = substr_replace(null, 'x', 0);
echo "result=", var_export($result, true), "\n";
if ('x' !== $result) {
    echo "fail: expected 'x'\n";
    exit(1);
}
echo "ok\n";
