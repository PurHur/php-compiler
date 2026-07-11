<?php

declare(strict_types=1);

$fail = 0;

$li = new LimitIterator(new ArrayIterator([1, 2, 3]), 0, 2);

try {
    $li->seek(-1);
    echo "fail: no throw\n";
    $fail = 1;
} catch (OutOfBoundsException $e) {
    echo "ok: OutOfBoundsException\n";
} catch (Throwable $e) {
    echo 'fail: '.get_class($e).': '.$e->getMessage()."\n";
    $fail = 1;
}

exit($fail);
