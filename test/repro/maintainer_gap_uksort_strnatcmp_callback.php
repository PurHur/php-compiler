<?php

declare(strict_types=1);

$fail = 0;

$a = ['a10' => 1, 'a2' => 2];
uksort($a, 'strnatcmp');
if (['a2', 'a10'] !== array_keys($a)) {
    echo 'FAIL uksort strnatcmp: ', implode(',', array_keys($a)), "\n";
    ++$fail;
}

$b = [3 => 'z', 1 => 'A', 2 => 'a'];
usort($b, 'strnatcasecmp');
if (['A', 'a', 'z'] !== $b) {
    echo 'FAIL usort strnatcasecmp: ', implode(',', $b), "\n";
    ++$fail;
}

$c = ['x' => 1, 'y' => 2, 'z' => 1];
uasort($c, 'strcmp');
if (['x', 'z', 'y'] !== array_keys($c)) {
    echo 'FAIL uasort strcmp keys: ', implode(',', array_keys($c)), "\n";
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
