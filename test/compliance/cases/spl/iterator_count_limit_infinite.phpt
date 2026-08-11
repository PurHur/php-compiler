--TEST--
iterator_count()/iterator_apply() on LimitIterator(InfiniteIterator) (#30237, php-src-strict)
--FILE--
<?php
$mk = static function (): LimitIterator {
    return new LimitIterator(new InfiniteIterator(new ArrayIterator([1])), 0, 3);
};

echo 'count=', iterator_count($mk()), "\n";

$n = 0;
foreach ($mk() as $_) {
    ++$n;
}
echo 'foreach=', $n, "\n";

$n = 0;
echo 'apply=', iterator_apply($mk(), static function () use (&$n) {
    ++$n;

    return true;
}), "\n";
echo 'apply_cb=', $n, "\n";

echo 'plain=', iterator_count(new ArrayIterator([1, 2, 3])), "\n";
--EXPECT--
count=3
foreach=3
apply=3
apply_cb=3
plain=3
