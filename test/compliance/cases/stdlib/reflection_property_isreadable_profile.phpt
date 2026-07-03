--TEST--
ReflectionProperty::isReadable()/isWritable() phantom on 8.2 reference profile (#15664, ext/reflection/php_reflection.c)
--FILE--
<?php
class ReflectionReadableReferenceProbe {
    public int $x = 1;
}
$r = new ReflectionProperty(ReflectionReadableReferenceProbe::class, 'x');
foreach (['isReadable', 'isWritable'] as $method) {
    echo $method, '=', method_exists($r, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
isReadable=no
isWritable=no
