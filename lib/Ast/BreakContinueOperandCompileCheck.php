<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\NodeVisitorAbstract;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Compile-time check: break/continue depth must be a positive integer (#32155).
 *
 * php-cfg {@see \PHPCfg\AstVisitor\LoopResolver} previously threw
 * {@code Too high of a count for Stmt_Continue} for {@code continue 0}, leaking
 * php-parser node type names. php-src fatals first when the literal is not {@code > 0}.
 *
 * php-src: Zend/zend_compile.c — zend_compile_break_continue(); depth must be {@code > 0}
 */
final class BreakContinueOperandCompileCheck extends NodeVisitorAbstract
{
    public const CONTINUE_MESSAGE = "'continue' operator accepts only positive integers";

    public const BREAK_MESSAGE = "'break' operator accepts only positive integers";

    private string $sourceFile = 'unknown';

    public function setSourceFile(string $sourceFile): void
    {
        $this->sourceFile = '' !== $sourceFile ? $sourceFile : 'unknown';
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Continue_) {
            $this->rejectNonPositive($node, $node->num, self::CONTINUE_MESSAGE);
        } elseif ($node instanceof Break_) {
            $this->rejectNonPositive($node, $node->num, self::BREAK_MESSAGE);
        }

        return null;
    }

    /**
     * Map LoopResolver LogicException into Zend-shaped CompileFatal (JIT must not echo on stdout).
     *
     * @return never
     */
    public static function rethrowAsCompileFatal(\LogicException $e, string $filename): never
    {
        $msg = $e->getMessage();
        if (!self::isBreakContinueCompileMessage($msg)) {
            throw $e;
        }
        $file = '' !== $filename ? $filename : 'unknown';
        throw new CompileFatal($file, 1, $msg);
    }

    public static function isBreakContinueCompileMessage(string $message): bool
    {
        return str_contains($message, "operator accepts only positive integers")
            || str_starts_with($message, "Cannot 'continue' ")
            || str_starts_with($message, "Cannot 'break' ");
    }

    private function rejectNonPositive(Node $stmt, ?Node $num, string $message): void
    {
        $value = self::integerLiteralValue($num);
        if (null === $value || $value >= 1) {
            return;
        }

        $file = $stmt->getAttribute('fileName');
        if (!is_string($file) || '' === $file) {
            $file = $this->sourceFile;
        }

        throw new CompileFatal($file, max(1, $stmt->getStartLine()), $message);
    }

    private static function integerLiteralValue(?Node $expr): ?int
    {
        if (null === $expr) {
            return null;
        }
        if ($expr instanceof LNumber) {
            return $expr->value;
        }
        if ($expr instanceof UnaryMinus && $expr->expr instanceof LNumber) {
            return -$expr->expr->value;
        }
        if ($expr instanceof UnaryPlus && $expr->expr instanceof LNumber) {
            return $expr->expr->value;
        }
        if (class_exists(\PhpParser\Node\Expr\Paren::class) && $expr instanceof \PhpParser\Node\Expr\Paren) {
            return self::integerLiteralValue($expr->expr);
        }

        return null;
    }
}
