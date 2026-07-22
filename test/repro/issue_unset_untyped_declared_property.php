<?php
error_reporting(E_ALL);
class C { public $a = 1; }
$c = new C();
unset($c->a);
echo 'isset=', var_export(isset($c->a), true), "\n";
echo 'prop_exists=', var_export(property_exists($c, 'a'), true), "\n";
try {
    echo 'read=', var_export($c->a, true), "\n";
} catch (Throwable $e) {
    echo 'throw=', get_class($e), ':', $e->getMessage(), "\n";
}
