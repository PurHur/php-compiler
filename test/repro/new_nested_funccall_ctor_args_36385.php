<?php

declare(strict_types=1);

/**
 * Nested FuncCall producers as `new` ctor args must EXEC_RETURN (#36385).
 *
 * binary-trees / `new Box(mk($n), mk($n+1))` — php-cfg hoists sibling FuncCalls with
 * dead arg temps; without treating New_ as a multi-arg sibling consumer the first
 * call becomes FUNCCALL_EXEC_NORETURN and ARG_SEND rematerializes the wrong callee.
 *
 * php-src: Zend/zend_compile.c zend_compile_new / zend_compile_func_call (ZEND_SEND_*).
 */
final class TreeNode36385
{
    public function __construct(
        public mixed $left,
        public mixed $right,
    ) {
    }
}

function bottomUpTree36385(int $depth): TreeNode36385
{
    if ($depth > 0) {
        return new TreeNode36385(bottomUpTree36385($depth - 1), bottomUpTree36385($depth - 1));
    }

    return new TreeNode36385(null, null);
}

function itemCheck36385(TreeNode36385 $node): int
{
    if (null === $node->left) {
        return 1;
    }

    return 1 + itemCheck36385($node->left) + itemCheck36385($node->right);
}

$t = bottomUpTree36385(3);
echo itemCheck36385($t), "\n";
