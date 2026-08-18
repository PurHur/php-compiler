<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Compile-time check: break/continue depth must be a positive integer (#32155)
 * and must not exceed the enclosing loop/switch nesting (#32207).
 *
 * php-cfg {@see \PHPCfg\AstVisitor\LoopResolver} previously threw
 * {@code Too high of a count for Stmt_Continue} for {@code continue 0} and
 * over-deep {@code continue N}, leaking php-parser node type names. php-src
 * fatals first when the literal is not {@code > 0}, then when depth exceeds
 * loop nesting: {@code Cannot 'continue' N levels}.
 *
 * php-src: Zend/zend_compile.c — zend_compile_break_continue()
 */
final class BreakContinueOperandCompileCheck extends NodeVisitorAbstract
{
    public const CONTINUE_MESSAGE = "'continue' operator accepts only positive integers";

    public const BREAK_MESSAGE = "'break' operator accepts only positive integers";

    private string $sourceFile = 'unknown';

    /** Current op-array loop/switch nesting (php-src CG(context).loop_nesting_level). */
    private int $loopLevels = 0;

    /** @var list<int> saved nesting when entering a nested function/closure/method */
    private array $savedLoopLevels = [];

    public function setSourceFile(string $sourceFile): void
    {
        $this->sourceFile = '' !== $sourceFile ? $sourceFile : 'unknown';
    }

    public function beforeTraverse(array $nodes)
    {
        $this->loopLevels = 0;
        $this->savedLoopLevels = [];

        return null;
    }

    public function enterNode(Node $node)
    {
        if (self::isOpArrayBoundary($node)) {
            $this->savedLoopLevels[] = $this->loopLevels;
            $this->loopLevels = 0;
        }
        if (self::isLoopOrSwitch($node)) {
            $this->loopLevels++;
        }

        if ($node instanceof Continue_) {
            $this->checkOperand($node, $node->num, 'continue', self::CONTINUE_MESSAGE);
        } elseif ($node instanceof Break_) {
            $this->checkOperand($node, $node->num, 'break', self::BREAK_MESSAGE);
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if (self::isLoopOrSwitch($node) && $this->loopLevels > 0) {
            $this->loopLevels--;
        }
        if (self::isOpArrayBoundary($node)) {
            $this->loopLevels = array_pop($this->savedLoopLevels) ?? 0;
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
        $msg = self::mapLoopResolverMessage($e->getMessage());
        if (null === $msg) {
            throw $e;
        }
        $file = '' !== $filename ? $filename : 'unknown';
        throw new CompileFatal($file, 1, $msg);
    }

    public static function isBreakContinueCompileMessage(string $message): bool
    {
        return str_contains($message, "operator accepts only positive integers")
            || str_starts_with($message, "Cannot 'continue' ")
            || str_starts_with($message, "Cannot 'break' ")
            || str_contains($message, 'Too high of a count for Stmt_Continue')
            || str_contains($message, 'Too high of a count for Stmt_Break')
            || 'Unimplemented Node Value Type' === $message;
    }

    public static function overdeepMessage(string $keyword, int $depth): string
    {
        return sprintf("Cannot '%s' %d levels", $keyword, $depth);
    }

    /**
     * Rewrite php-cfg LoopResolver internals to Zend zend_compile_break_continue() text.
     */
    public static function mapLoopResolverMessage(string $message): ?string
    {
        if (str_contains($message, 'Too high of a count for Stmt_Continue')) {
            return self::overdeepMessage('continue', 2);
        }
        if (str_contains($message, 'Too high of a count for Stmt_Break')) {
            return self::overdeepMessage('break', 2);
        }
        if ('Unimplemented Node Value Type' === $message) {
            // LoopResolver does not name continue vs break; AST visitor rejects floats first.
            return self::CONTINUE_MESSAGE;
        }
        if (str_contains($message, "operator accepts only positive integers")
            || str_starts_with($message, "Cannot 'continue' ")
            || str_starts_with($message, "Cannot 'break' ")
        ) {
            return $message;
        }

        return null;
    }

    private function checkOperand(Node $stmt, ?Node $num, string $keyword, string $positiveMessage): void
    {
        if (self::isNonIntegerLiteral($num)) {
            $this->throwFatal($stmt, $positiveMessage);
        }

        $value = self::integerLiteralValue($num);
        if (null === $value) {
            return;
        }
        if ($value < 1) {
            $this->throwFatal($stmt, $positiveMessage);
        }
        // php-src: depth > loop_nesting_level && loop_nesting_level == 0 → not-in-context
        // (LoopResolver). Over-deep only when there is at least one enclosing loop/switch.
        if ($this->loopLevels >= 1 && $value > $this->loopLevels) {
            $this->throwFatal($stmt, self::overdeepMessage($keyword, $value));
        }
    }

    private function throwFatal(Node $stmt, string $message): never
    {
        $file = $stmt->getAttribute('fileName');
        if (!is_string($file) || '' === $file) {
            $file = $this->sourceFile;
        }

        throw new CompileFatal($file, max(1, $stmt->getStartLine()), $message);
    }

    private static function isLoopOrSwitch(Node $node): bool
    {
        return $node instanceof For_
            || $node instanceof Foreach_
            || $node instanceof While_
            || $node instanceof Do_
            || $node instanceof Switch_;
    }

    private static function isOpArrayBoundary(Node $node): bool
    {
        return $node instanceof Function_
            || $node instanceof ClassMethod
            || $node instanceof Closure
            || $node instanceof ArrowFunction;
    }

    private static function isNonIntegerLiteral(?Node $expr): bool
    {
        if (null === $expr) {
            return false;
        }
        if ($expr instanceof DNumber) {
            return true;
        }
        if (class_exists(\PhpParser\Node\Scalar\Float_::class) && $expr instanceof \PhpParser\Node\Scalar\Float_) {
            return true;
        }
        if ($expr instanceof UnaryMinus || $expr instanceof UnaryPlus) {
            return self::isNonIntegerLiteral($expr->expr);
        }
        if (class_exists(\PhpParser\Node\Expr\Paren::class) && $expr instanceof \PhpParser\Node\Expr\Paren) {
            return self::isNonIntegerLiteral($expr->expr);
        }

        return false;
    }

    private static function integerLiteralValue(?Node $expr): ?int
    {
        if (null === $expr) {
            return null;
        }
        if ($expr instanceof LNumber) {
            return $expr->value;
        }
        if (class_exists(\PhpParser\Node\Scalar\Int_::class) && $expr instanceof \PhpParser\Node\Scalar\Int_) {
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
