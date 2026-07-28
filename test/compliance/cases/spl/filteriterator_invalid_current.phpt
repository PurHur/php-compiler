--TEST--
FilterIterator current()/key() when no accepted element — NULL (#24272, ext/spl/spl_iterators.c)
--FILE--
<?php
class RejectAllFilter extends FilterIterator
{
    public function accept(): bool
    {
        return false;
    }
}
$f = new RejectAllFilter(new ArrayIterator([1, 2, 3]));
$f->rewind();
echo 'valid=', (int) $f->valid(), ' current=', var_export($f->current(), true), ' key=', var_export($f->key(), true), "\n";

class KeepEven extends FilterIterator
{
    public function accept(): bool
    {
        return 0 === ($this->current() % 2);
    }
}
$e = new KeepEven(new ArrayIterator([1, 2, 3, 4]));
echo 'even:', implode(',', iterator_to_array($e)), "\n";
?>
--EXPECT--
valid=0 current=NULL key=NULL
even:2,4
