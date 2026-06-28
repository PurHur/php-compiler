--TEST--
stdlib ReflectionProperty::isReadable()/isWritable() phantom on reference profile (#13284)
--FILE--
<?php
class ReflectionReadablePhantomProbe {
    public int $x = 1;
}
$r = new ReflectionProperty(ReflectionReadablePhantomProbe::class, 'x');
foreach (['isReadable', 'isWritable'] as $method) {
    echo $method, '=', method_exists($r, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
isReadable=no
isWritable=no
