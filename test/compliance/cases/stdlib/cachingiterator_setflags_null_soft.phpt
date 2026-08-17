--TEST--
CachingIterator::setFlags(null) — soft-null DEP then flags=0 (#31694)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});
$c = new CachingIterator(new ArrayIterator([1]), CachingIterator::FULL_CACHE);
try {
    $c->setFlags(null);
    echo 'flags=' . $c->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
?>
--EXPECT--
DEP:CachingIterator::setFlags(): Passing null to parameter #1 ($flags) of type int is deprecated
flags=0
