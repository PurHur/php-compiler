<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\Frame;
use PHPCompiler\OpCode;

/**
 * Zend-shaped ArgumentCountError messages for user function/method calls (issue #10176).
 *
 * php-src: Zend/zend_execute.c — too few arguments guard
 */
final class ParamArgumentCountError
{
    public static function forTooFewAtReceive(Frame $frame, int $missingParamIndex): \ArgumentCountError
    {
        $block = $frame->block;
        $paramCount = \count($block->paramNames);
        $hasOptional = false;
        for ($i = 0; $i < $paramCount; ++$i) {
            if (self::paramHasDefault($block, $i)) {
                $hasOptional = true;
                break;
            }
        }
        $passed = self::countPassedUserArgs($frame);
        $expectedPhrase = $hasOptional
            ? \sprintf('at least %d expected', self::countMinimumRequired($block))
            : \sprintf('exactly %d expected', $paramCount);
        [$scriptPath, $callSiteLine] = self::callSite($frame);
        $function = self::formatUserFunctionName(self::resolveFunctionName($frame));

        return new \ArgumentCountError(\sprintf(
            'Too few arguments to function %s(), %d passed in %s on line %d and %s',
            $function,
            $passed,
            $scriptPath,
            $callSiteLine,
            $expectedPhrase
        ));
    }

    private static function countPassedUserArgs(Frame $frame): int
    {
        $passed = \count($frame->calledArgs);
        if (
            null !== $frame->block->func
            && null !== $frame->block->func->class
            && !(($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)
            && \array_key_exists(0, $frame->calledArgs)
        ) {
            $passed = max(0, $passed - 1);
        }

        return $passed;
    }

    /** @return array{0: string, 1: int} */
    private static function callSite(Frame $frame): array
    {
        $caller = $frame->parent ?? $frame;
        $scriptPath = '' !== $caller->scriptPath
            ? $caller->scriptPath
            : ExceptionSupport::throwSiteFile($caller);
        $callSiteLine = $caller->callSiteLine;
        if ($callSiteLine <= 0) {
            for ($f = $caller; null !== $f; $f = $f->parent) {
                if ($f->callSiteLine > 0) {
                    $callSiteLine = $f->callSiteLine;
                    break;
                }
            }
        }
        if ($callSiteLine <= 0) {
            $callSiteLine = 1;
        }

        return [$scriptPath, $callSiteLine];
    }

    private static function resolveFunctionName(Frame $frame): string
    {
        if ($frame->call instanceof \PHPCompiler\Func\PHP) {
            return $frame->call->getName();
        }
        $method = null;
        if (null !== $frame->block->func && \is_string($frame->block->func->name)) {
            $method = $frame->block->func->name;
        }
        if (null !== $method) {
            $selfVar = null;
            if ([] !== $frame->callArgs) {
                $selfVar = $frame->callArgs[0]->resolveIndirect();
            } elseif (\array_key_exists(0, $frame->calledArgs)) {
                $selfVar = $frame->calledArgs[0]->resolveIndirect();
            }
            if (null !== $selfVar && Variable::TYPE_OBJECT === $selfVar->type) {
                return $selfVar->toObject()->class->name.'::'.$method;
            }
        }
        if (null !== $method) {
            return $method;
        }

        return '{closure}';
    }

    public static function formatUserFunctionName(string $name): string
    {
        if (!str_contains($name, '@anonymous')) {
            return $name;
        }

        return preg_replace('/(@anonymous)\0[^\0]+?(?=::|$)/', '$1', $name) ?? $name;
    }

    private static function countMinimumRequired(Block $block): int
    {
        $paramCount = \count($block->paramNames);
        for ($i = 0; $i < $paramCount; ++$i) {
            if (self::paramHasDefault($block, $i)) {
                return $i;
            }
        }

        return $paramCount;
    }

    private static function paramHasDefault(Block $block, int $paramIndex): bool
    {
        if (isset($block->paramRuntimeDefaultInitBlocks[$paramIndex])) {
            return true;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type || (int) $op->arg2 !== $paramIndex) {
                continue;
            }

            return null !== $op->arg3;
        }

        return false;
    }
}
