<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Script;
use PHPCompiler\Cfg\OpSubBlockAccess;

/**
 * Reject anonymous class `new` in function-local static initializers (#15873).
 *
 * php-src: Zend/zend_compile.c — zend_compile_const_expr() rejects ZEND_ACC_ANON_CLASS.
 * Named `new` (e.g. `new stdClass`) remains allowed as runtime static init (#4352).
 *
 * @see Zend/zend_compile.c — zend_compile_const_expr()
 */
final class FunctionStaticAnonymousClassCompileCheck
{
    public const MESSAGE = 'Cannot use anonymous class in constant expression';

    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->functions as $func) {
            $check->validateFunc($func);
        }
    }

    private function validateFunc(Func $func): void
    {
        if (null === $func->cfg) {
            return;
        }
        $this->walkBlock($func->cfg, $func->callableOp);
    }

    /**
     * @param Op|null $contextOp owning callable for file/line on fatal
     */
    private function walkBlock(CfgBlock $block, ?Op $contextOp): void
    {
        // CFG sub-blocks include jump targets, so loops form cycles — BFS with
        // a seen-set like EnumParentCompileCheck::walkCfg (#15884 recursed
        // unboundedly and exhausted memory linting any looping function).
        $seen = new \SplObjectStorage();
        $queue = [$block];
        while ([] !== $queue) {
            $current = array_shift($queue);
            if ($seen->contains($current)) {
                continue;
            }
            $seen->attach($current);
            foreach ($current->children as $child) {
                if ($child instanceof Op\Terminal\StaticVar) {
                    $this->rejectAnonymousClassStaticInit($child, $contextOp);
                }
                OpSubBlockAccess::enqueueSubBlocks($child, $queue);
            }
        }
    }

    private function rejectAnonymousClassStaticInit(Op\Terminal\StaticVar $staticVar, ?Op $contextOp): void
    {
        if (null === $staticVar->defaultBlock) {
            return;
        }
        foreach ($staticVar->defaultBlock->children as $child) {
            if ($child instanceof Op\Stmt\Class_) {
                throw new CompileFatal(
                    $staticVar->getFile() ?: ($contextOp instanceof Op ? $contextOp->getFile() : 'unknown'),
                    $staticVar->getLine() ?: ($contextOp instanceof Op ? $contextOp->getLine() : 1),
                    self::MESSAGE
                );
            }
        }
    }
}
