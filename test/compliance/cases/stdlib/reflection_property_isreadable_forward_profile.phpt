--TEST--
stdlib ReflectionProperty::isReadable()/isWritable() on 8.4 forward profile (#13663, ext/reflection/php_reflection.c)
--FILE--
<?php
class ReflectionReadableForwardProbe {
    public int $x = 1;
}
$r = new ReflectionProperty(ReflectionReadableForwardProbe::class, 'x');
foreach (['isReadable', 'isWritable'] as $method) {
    echo $method, '=', method_exists($r, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
isReadable=yes
isWritable=yes
