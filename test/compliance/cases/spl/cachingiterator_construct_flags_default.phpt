--TEST--
SPL CachingIterator construct default CALL_TOSTRING; explicit null flags → 0 (#22336, ext/spl/spl_iterators.c)
--FILE--
<?php
$c = new CachingIterator(new ArrayIterator([1]));
echo "default=", $c->getFlags(), "\n";
echo "default_is_call_tostring=", ($c->getFlags() === CachingIterator::CALL_TOSTRING) ? "yes" : "no", "\n";

$c2 = new CachingIterator(new ArrayIterator([1]), null);
echo "null_flags=", $c2->getFlags(), "\n";

$c3 = new CachingIterator(new ArrayIterator([1]), CachingIterator::FULL_CACHE);
echo "full_cache=", $c3->getFlags(), "\n";

$r = new RecursiveCachingIterator(new RecursiveArrayIterator([1]));
echo "rci_default=", $r->getFlags(), "\n";
$r2 = new RecursiveCachingIterator(new RecursiveArrayIterator([1]), null);
echo "rci_null_flags=", $r2->getFlags(), "\n";
?>
--EXPECT--
default=1
default_is_call_tostring=yes
null_flags=0
full_cache=256
rci_default=1
rci_null_flags=0
