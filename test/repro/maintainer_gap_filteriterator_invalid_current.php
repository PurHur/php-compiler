<?php
/**
 * #24272 — FilterIterator current()/key() when no accepted element must be NULL.
 */
error_reporting(E_ALL);
class RejectAllFilter extends FilterIterator
{
    public function accept(): bool
    {
        return false;
    }
}
$f = new RejectAllFilter(new ArrayIterator([1, 2, 3]));
$f->rewind();
$c = $f->current();
$k = $f->key();
$v = $f->valid();
echo 'valid=', (int) $v, ' current=', var_export($c, true), ' key=', var_export($k, true), "\n";
exit((!$v && null === $c && null === $k) ? 0 : 1);
