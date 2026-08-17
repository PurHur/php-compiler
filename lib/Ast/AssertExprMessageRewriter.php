<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\ext\standard\AssertOptionsJitHelper;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Zend {@code assert()} compile-time handling (zend_compile.c {@code zend_compile_assert}).
 *
 * - {@code zend.assertions < 0}: replace the call with constant {@code true} so the
 *   condition and description are not evaluated (#31857).
 * - Otherwise inject the pretty-printed expression as the description when omitted
 *   (#29630), matching php-src {@code assert($expr)} → {@code assert($expr, 'assert($expr)')}.
 */
final class AssertExprMessageRewriter extends NodeVisitorAbstract
{
    private PrettyPrinter $printer;

    public function __construct(?PrettyPrinter $printer = null)
    {
        $this->printer = $printer ?? new PrettyPrinter();
    }

    public function enterNode(Node $node)
    {
        if (!$node instanceof FuncCall || !$node->name instanceof Name) {
            return null;
        }
        if ('assert' !== strtolower($node->name->toString())) {
            return null;
        }
        if ($node->isFirstClassCallable()) {
            return null;
        }
        if (AssertOptionsJitHelper::shouldCompileOutAssert()) {
            return new ConstFetch(new Name('true'), $node->getAttributes());
        }
        if (1 !== \count($node->args)) {
            return null;
        }
        $arg = $node->args[0];
        if ($arg->unpack) {
            return null;
        }

        $forPrint = clone $node;
        $forPrint->name = new Name('assert', $node->name->getAttributes());
        $text = $this->printer->prettyPrintExpr($forPrint);
        $node->args[] = new Arg(new String_($text));

        return null;
    }
}
