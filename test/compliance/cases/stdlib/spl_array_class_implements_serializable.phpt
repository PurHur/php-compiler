--TEST--
ArrayObject and ArrayIterator class_implements() include Serializable (#14034)
--FILE--
<?php
$ao = class_implements(new ArrayObject());
if (!isset($ao['Serializable'])) {
    echo 'ao_missing_serializable', "\n";
    exit(1);
}
$ai = class_implements(new ArrayIterator([]));
if (!isset($ai['Serializable'])) {
    echo 'ai_missing_serializable', "\n";
    exit(1);
}
if (!isset($ai['SeekableIterator'])) {
    echo 'ai_missing_seekable', "\n";
    exit(1);
}
echo "ok\n";
?>
--EXPECT--
ok
