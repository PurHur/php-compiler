<?php
/**
 * Issue #19632 — __PHP_Incomplete_Class property read/isset/write guards.
 */
class X { public $a = 1; }
$s = serialize(new X);
$o = unserialize($s, ['allowed_classes' => false]);
echo get_class($o), PHP_EOL;
set_error_handler(function () { echo "WARN\n"; return true; });
var_export(isset($o->a)); echo PHP_EOL;
$v = $o->a;
echo 'val=', var_export($v, true), PHP_EOL;
try { $o->a = 5; echo "wrote\n"; } catch (Throwable $e) { echo "ERR\n"; }
