<?php

declare(strict_types=1);

$li = new LimitIterator(new ArrayIterator([1, 2, 3]), 1, 1);
$before = $li->current();
$li->rewind();
$after = $li->current();

if (null !== $before) {
    fwrite(STDERR, "before=".var_export($before, true)." expected NULL\n");
    exit(1);
}
if (2 !== $after) {
    fwrite(STDERR, "after=".var_export($after, true)." expected 2\n");
    exit(1);
}
echo "ok\n";
