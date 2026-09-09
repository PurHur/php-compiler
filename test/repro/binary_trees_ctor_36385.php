<?php
final class TreeNode {
    public function __construct(public ?TreeNode $left, public ?TreeNode $right) {}
}
function bottomUpTree(int $depth): TreeNode {
    if ($depth > 0) {
        return new TreeNode(bottomUpTree($depth - 1), bottomUpTree($depth - 1));
    }
    return new TreeNode(null, null);
}
$t = bottomUpTree(2);
echo (null === $t->left) ? "n\n" : "y\n";
