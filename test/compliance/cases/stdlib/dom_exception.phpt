--TEST--
stdlib DOMException class + catch hierarchy (#15430, ext/dom/domexception.c)
--FILE--
<?php
if (!class_exists('DOMException')) {
    echo "missing\n";
    exit(1);
}
$doc = new DOMDocument();
try {
    $doc->createEntityReference('bad name');
} catch (DOMException $e) {
    echo "builtin_caught\n";
    echo $e->getMessage(), "\n";
}
try {
    throw new DOMException('probe', DOMSTRING_SIZE_ERR);
} catch (DOMException $e) {
    echo $e->getMessage(), "\n";
    echo $e->getCode(), "\n";
    echo $e instanceof Exception ? "instance_ok\n" : "instance_bad\n";
}
?>
--EXPECT--
builtin_caught
Invalid Character Error
probe
2
instance_ok
