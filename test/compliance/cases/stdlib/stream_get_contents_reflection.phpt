--TEST--
stdlib stream_get_contents Reflection return string|false + ?int length (#25750)
--FILE--
<?php
$r = new ReflectionFunction('stream_get_contents');
echo 'ret=', (string) $r->getReturnType(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : 'none', "\n";
}
$s = fopen('php://memory', 'r+');
fwrite($s, 'abcdef');
rewind($s);
echo 'null_length=', var_export(stream_get_contents($s, null, 1), true), "\n";
fclose($s);
?>
--EXPECT--
ret=string|false
stream type=none
length type=?int
offset type=int
null_length='bcdef'
