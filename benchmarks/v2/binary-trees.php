<?php

declare(strict_types=1);

/**
 * Binary-trees (scaled) — object allocation / recursion (#36385).
 */

final class TreeNode
{
    public function __construct(
        public ?TreeNode $left,
        public ?TreeNode $right,
    ) {
    }
}

function bottomUpTree(int $depth): TreeNode
{
    if ($depth > 0) {
        return new TreeNode(bottomUpTree($depth - 1), bottomUpTree($depth - 1));
    }

    return new TreeNode(null, null);
}

function itemCheck(TreeNode $node): int
{
    if (null === $node->left) {
        return 1;
    }

    return 1 + itemCheck($node->left) + itemCheck($node->right);
}

$minDepth = 4;
$maxDepth = 10;
$stretchDepth = $maxDepth + 1;

$stretch = bottomUpTree($stretchDepth);
echo 'stretch tree of depth '.$stretchDepth."\t check: ".itemCheck($stretch)."\n";

$longLived = bottomUpTree($maxDepth);

for ($depth = $minDepth; $depth <= $maxDepth; $depth += 2) {
    $iterations = 1 << ($maxDepth - $depth + $minDepth);
    $check = 0;
    for ($i = 1; $i <= $iterations; ++$i) {
        $check += itemCheck(bottomUpTree($depth));
    }
    echo $iterations."\t trees of depth {$depth}\t check: {$check}\n";
}

echo 'long lived tree of depth '.$maxDepth."\t check: ".itemCheck($longLived)."\n";
