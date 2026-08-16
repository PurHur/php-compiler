--TEST--
LimitIterator null offset/limit — soft-null DEP then OutOfBoundsException (#31621)
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
    $li = new LimitIterator(new ArrayIterator([1, 2, 3]), null, null);
    echo 'ok:', json_encode(iterator_to_array($li)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP:LimitIterator::__construct(): Passing null to parameter #2 ($offset) of type int is deprecated
DEP:LimitIterator::__construct(): Passing null to parameter #3 ($limit) of type int is deprecated
OutOfBoundsException: Cannot seek to 0 which is behind offset 0 plus count 0
