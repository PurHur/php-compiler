--TEST--
CachingIterator::__construct(null $flags) — soft-null DEP then flags=0 (#31679)
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
try {
    $c = new CachingIterator(new ArrayIterator([1]), null);
    echo 'flags=' . $c->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
?>
--EXPECT--
DEP:CachingIterator::__construct(): Passing null to parameter #2 ($flags) of type int is deprecated
flags=0
