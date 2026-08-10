<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Inject Zend-style assert() expression text as the description when omitted (#29630).
 *
 * php-src compiles {@code assert($expr)} as {@code assert($expr, 'assert($expr)')} so
 * AssertionError / E_WARNING messages use the normalized expression (zend_compile.c /
 * ext/standard/assert.c). Without a description, this compiler previously always used
 * {@code assert(): assert(false) failed}.
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
