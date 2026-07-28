<?php
/**
 * #24295 — LimitIterator rewind past inner end must OutOfBoundsException (SeekableIterator).
 */
error_reporting(E_ALL);
$fail = 0;
try {
    $it = new LimitIterator(new ArrayIterator([1, 2, 3]), 5, 1);
    $it->rewind();
    echo "fail: no throw valid=", (int) $it->valid(), "\n";
    $fail = 1;
} catch (OutOfBoundsException $e) {
    echo 'OutOfBoundsException: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'fail: ', get_class($e), ': ', $e->getMessage(), "\n";
    $fail = 1;
}
exit($fail);
