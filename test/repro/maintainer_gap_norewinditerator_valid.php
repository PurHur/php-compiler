<?php
declare(strict_types=1);

$inner = new ArrayIterator(['a', 'b', 'c']);
$wrap = new NoRewindIterator($inner);
$ok = $wrap->valid();
echo 'valid=' . ($ok ? 'true' : 'false') . "\n";
exit($ok ? 0 : 1);
