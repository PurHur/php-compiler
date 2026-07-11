--TEST--
stdlib array_intersect()/array_diff() inline nested str_split(str_repeat()) operands (#15488)
--FILE--
<?php
declare(strict_types=1);

$expectedIntersect = ['a'];
$inlineIntersect = array_intersect(str_split(str_repeat('ab', 1)), str_split(str_repeat('a', 1)));
if ($inlineIntersect !== $expectedIntersect) {
    echo 'fail intersect inline';
    exit(1);
}

$expectedDiff = [1 => 'b', 2 => 'c'];
$inlineDiff = array_diff(str_split(str_repeat('abc', 1)), str_split(str_repeat('a', 1)));
if ($inlineDiff !== $expectedDiff) {
    echo 'fail diff inline';
    exit(1);
}

$left = str_split(str_repeat('ab', 1));
$right = str_split(str_repeat('a', 1));
if (array_intersect($left, $right) !== $expectedIntersect) {
    echo 'fail intersect variable';
    exit(1);
}

echo "ok\n";
?>
--EXPECT--
ok
