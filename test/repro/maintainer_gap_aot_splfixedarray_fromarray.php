<?php
// Maintainer gap: SplFixedArray::fromArray under user-script AOT (#26793).
$a = SplFixedArray::fromArray([10, 20, 30], false);
echo count($a), '|', $a[2], "\n";
