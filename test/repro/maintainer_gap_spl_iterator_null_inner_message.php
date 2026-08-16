<?php
/**
 * #31513 — SPL outer-iterator null TypeError cites concrete method + Iterator
 * (not IteratorIterator / Traversable).
 *
 * php-src: ext/spl/spl_iterators.c / spl_iterators.stub.php
 */
error_reporting(E_ALL);

function catchMsg(callable $fn): string
{
    try {
        $fn();
        return 'NO_THROW';
    } catch (Throwable $e) {
        return get_class($e).': '.$e->getMessage();
    }
}

echo 'append: ', catchMsg(static function (): void {
    (new AppendIterator())->append(null);
}), "\n";

echo 'limit: ', catchMsg(static function (): void {
    new LimitIterator(null);
}), "\n";

echo 'caching: ', catchMsg(static function (): void {
    new CachingIterator(null);
}), "\n";

echo 'norewind: ', catchMsg(static function (): void {
    new NoRewindIterator(null);
}), "\n";

echo 'infinite: ', catchMsg(static function (): void {
    new InfiniteIterator(null);
}), "\n";

echo 'multi: ', catchMsg(static function (): void {
    (new MultipleIterator())->attachIterator(null);
}), "\n";

echo 'rii: ', catchMsg(static function (): void {
    new RecursiveIteratorIterator(null);
}), "\n";

echo 'ii: ', catchMsg(static function (): void {
    new IteratorIterator(null);
}), "\n";
