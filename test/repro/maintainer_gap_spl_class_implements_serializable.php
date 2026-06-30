<?php
// Issue #14034 — ArrayObject/ArrayIterator class_implements() must include Serializable.
$ao = class_implements(new ArrayObject());
if (!isset($ao['Serializable'])) {
    echo "ArrayObject missing Serializable interface\n";
    var_export($ao);
    echo "\n";
    exit(1);
}
$ai = class_implements(new ArrayIterator([]));
if (!isset($ai['Serializable'])) {
    echo "ArrayIterator missing Serializable interface\n";
    var_export($ai);
    echo "\n";
    exit(1);
}
if (!isset($ai['SeekableIterator'])) {
    echo "ArrayIterator missing SeekableIterator interface\n";
    exit(1);
}
echo "ok\n";
