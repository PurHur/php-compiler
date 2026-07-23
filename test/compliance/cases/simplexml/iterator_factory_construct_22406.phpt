--TEST--
SimpleXML: SimpleXMLIterator factory class_name + string construct (#22406, ext/simplexml/sxe.c)
--FILE--
<?php
$x = simplexml_load_string('<r><a>1</a></r>', 'SimpleXMLIterator');
echo 'load_class=', get_class($x), "\n";
echo 'load_instanceof=', ($x instanceof SimpleXMLIterator) ? '1' : '0', "\n";
echo 'load_recursive=', ($x instanceof RecursiveIterator) ? '1' : '0', "\n";

$y = new SimpleXMLIterator('<r><a/></r>');
echo 'new_class=', get_class($y), "\n";
echo 'new_instanceof=', ($y instanceof SimpleXMLIterator) ? '1' : '0', "\n";
$y->rewind();
echo 'iter_valid=', $y->valid() ? '1' : '0', "\n";
if ($y->valid()) {
    $c = $y->current();
    echo 'child_class=', get_class($c), "\n";
    echo 'child_instanceof=', ($c instanceof SimpleXMLIterator) ? '1' : '0', "\n";
}

try {
    simplexml_load_string('<r/>', 'stdClass');
    echo "bad_class=ok\n";
} catch (Throwable $e) {
    echo 'bad_class=', get_class($e), "\n";
}
--EXPECT--
load_class=SimpleXMLIterator
load_instanceof=1
load_recursive=1
new_class=SimpleXMLIterator
new_instanceof=1
iter_valid=1
child_class=SimpleXMLIterator
child_instanceof=1
bad_class=TypeError
