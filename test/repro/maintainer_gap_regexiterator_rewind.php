<?php
declare(strict_types=1);

$it = new RegexIterator(new ArrayIterator(['a1', 'b2', 'c3']), '/\d/');
$it->rewind();
$current = $it->current();
echo "current={$current}\n";
exit('a1' === $current ? 0 : 1);
