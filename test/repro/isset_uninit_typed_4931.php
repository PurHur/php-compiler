<?php
class T { public int $x; }
$o = new T();
var_export(isset($o->x));
echo "\n";
try {
    var_export(empty($o->x));
} catch (Error $e) {
    echo 'empty Error ok', "\n";
}
