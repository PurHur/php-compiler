--TEST--
strtok Reflection return string|false (VM, issue #25760, string.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('strtok');
echo 'strtok=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$tok = strtok('a b', ' ');
echo 'first=', ($tok === 'a') ? '1' : '0', "\n";
$tok = strtok(' ');
echo 'second=', ($tok === 'b') ? '1' : '0', "\n";
$tok = strtok(' ');
echo 'end=', (false === $tok) ? '1' : '0', "\n";
?>
--EXPECT--
strtok=string|false
first=1
second=1
end=1
