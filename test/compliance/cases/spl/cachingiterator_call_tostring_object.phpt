--TEST--
CachingIterator CALL_TOSTRING invokes object __toString (#24256)
--FILE--
<?php
class CachingIteratorCallToStringObj {
    public function __toString() {
        return 'X';
    }
}
$it = new CachingIterator(
    new ArrayIterator([new CachingIteratorCallToStringObj()]),
    CachingIterator::CALL_TOSTRING
);
$it->rewind();
echo (string)$it, "\n";
echo $it->__toString(), "\n";

$it2 = new CachingIterator(new ArrayIterator(['hi', 7, true, null]), CachingIterator::CALL_TOSTRING);
$it2->rewind();
echo (string)$it2, "\n";
$it2->next();
echo (string)$it2, "\n";
$it2->next();
echo (string)$it2, "\n";
$it2->next();
echo var_export((string)$it2, true), "\n";
?>
--EXPECT--
X
X
hi
7
1
''
