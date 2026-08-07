--TEST--
ReflectionProperty::isReadable()/isWritable() phantom on PROFILE=8.4 (#28533, ext/reflection/php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class ReflectionReadableProfile84Probe {
    public int $x = 1;
}
$r = new ReflectionProperty(ReflectionReadableProfile84Probe::class, 'x');
foreach (['isReadable', 'isWritable'] as $method) {
    echo $method, '=', method_exists($r, $method) ? 'yes' : 'no', "\n";
}
--EXPECT--
isReadable=no
isWritable=no
