--TEST--
Language: property hook asymmetric access — set-only read / get-only write (#18072, Zend/zend_property_hooks.c)
--FILE--
<?php
final class SetOnly { public string $x { set(string $v) { $this->x = $v; } } }
$c = new SetOnly(); $c->x = "b";
echo $c->x, "\n";

final class GetOnly { public string $x = "a" { get => strtoupper($this->x); } }
$c2 = new GetOnly();
try { $c2->x = "b"; echo "write_ok\n"; } catch (Error $e) { echo "write_error\n"; }
--EXPECT--
b
write_error
