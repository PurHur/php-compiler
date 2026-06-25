<?php
// Issue #11754 — json_decode() int $assoc coerces to bool without strict_types (ext/json/php_json.c).
$o = json_decode('{}', 0);
if (!is_object($o)) {
    echo "fail object\n";
    exit(1);
}
$a = json_decode('[]', 1);
if (!is_array($a)) {
    echo "fail array1\n";
    exit(1);
}
$b = json_decode('[]', 512);
if (!is_array($b)) {
    echo "fail array512\n";
    exit(1);
}
echo "ok\n";
