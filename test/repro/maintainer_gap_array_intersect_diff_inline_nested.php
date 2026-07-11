<?php

declare(strict_types=1);

$expectedIntersect = ['a'];
$inlineIntersect = array_intersect(str_split(str_repeat('ab', 1)), str_split(str_repeat('a', 1)));
if ($inlineIntersect !== $expectedIntersect) {
    echo 'fail intersect inline: got ', var_export($inlineIntersect, true), ' expected ', var_export($expectedIntersect, true), "\n";
    exit(1);
}

$expectedDiff = [1 => 'b', 2 => 'c'];
$inlineDiff = array_diff(str_split(str_repeat('abc', 1)), str_split(str_repeat('a', 1)));
if ($inlineDiff !== $expectedDiff) {
    echo 'fail diff inline: got ', var_export($inlineDiff, true), ' expected ', var_export($expectedDiff, true), "\n";
    exit(1);
}

$left = str_split(str_repeat('ab', 1));
$right = str_split(str_repeat('a', 1));
$variableIntersect = array_intersect($left, $right);
if ($variableIntersect !== $expectedIntersect) {
    echo 'fail intersect variable: got ', var_export($variableIntersect, true), "\n";
    exit(1);
}

$leftDiff = str_split(str_repeat('abc', 1));
$rightDiff = str_split(str_repeat('a', 1));
$variableDiff = array_diff($leftDiff, $rightDiff);
if ($variableDiff !== $expectedDiff) {
    echo 'fail diff variable: got ', var_export($variableDiff, true), "\n";
    exit(1);
}

echo "ok\n";
