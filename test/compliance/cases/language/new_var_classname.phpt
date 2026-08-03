--TEST--
language: JIT/AOT new $class variable classname (zend_compile.c ZEND_NEW, #27156)
--FILE--
<?php
$class = 'stdClass';
$o = new $class;
echo get_class($o), "\n";

$class2 = 'Exception';
try {
    throw new $class2('boom');
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$bad = 'NotARealClassXYZ27156';
try {
    new $bad;
    echo "no error\n";
} catch (Error $e) {
    echo get_class($e), "\n";
    echo (str_contains($e->getMessage(), 'NotARealClassXYZ27156')
        || str_contains($e->getMessage(), 'Class not found')
        || $e->getMessage() === 'Class not found')
        ? "msg-ok\n"
        : ('msg-bad:'.$e->getMessage()."\n");
}
?>
--EXPECT--
stdClass
Exception:boom
Error
msg-ok
