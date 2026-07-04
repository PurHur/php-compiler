<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});

$o = new stdClass;
var_export($o->missing);
echo "\n";
echo isset($o->missing) ? 'isset' : 'not', "\n";
echo property_exists($o, 'missing') ? 'exists' : 'not', "\n";

class C {
    public int $declared = 1;
}
$c = new C;
var_export($c->missing);
echo "\n";

echo "ok\n";
