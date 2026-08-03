<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\StringJsonDecode;
use PHPCompiler\JIT\CallUnpackHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * Compile-time json_encode() for inline array literals — avoids deferred AOT stubs (#14040).
 *
 * Also folds `json_encode(preg_split(lit…))` when args are compile-time (#27080),
 * `json_encode(array_replace_recursive(lit…))` (#26977), and
 * `json_encode(array_flip(lit…))` (#27072) — thin AOT NestedJIT cannot yet
 * export runtime string-key hashtables for `__compiler_json_encode_array`.
 *
 * php-src: ext/json/php_json.c — php_json_encode
 */
final class JitJsonEncodeCompileTime
{
    public static function tryEncode(
        Context $context,
        ?Block $block,
        ?Operand $operand,
        int $flags
    ): ?Value {
        if (null === $block || null === $operand) {
            return null;
        }
        $vmArray = CallUnpackHelper::tryCompileTimeArrayFromOperand($block, $operand);
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromPregSplit($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromArrayReplaceRecursive($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromArrayFlip($block, $operand);
        }
        if (null === $vmArray) {
            $foldedFalse = self::tryEncodePregSplitFalse($context, $block, $operand, $flags);
            if (null !== $foldedFalse) {
                return $foldedFalse;
            }

            return null;
        }
        try {
            $exported = VmJson::export($vmArray);
        } catch (VmJsonExportException $e) {
            if (
                VmJsonFlags::throwsOnError($flags)
                && !VmJsonFlags::partialOutputOnError($flags)
            ) {
                VmJson::throwExceptionPreservingLastError($e->errorCode);
            }
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \JsonException(VmJson::errorMsgForCode($e->errorCode), $e->errorCode);
            }
            // Soft-fail: bake false + sticky last_error (avoid AOT runtime INF crash, #26792).
            self::emitSetLastError($context, $e->errorCode);

            return self::emitFalse($context);
        }
        $encoded = VmJsonFormat::encodeExported($exported, $flags);
        $sticky = VmJson::lastError();
        if (false === $encoded) {
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \LogicException('json_encode() THROW path returned false');
            }
            self::emitSetLastError($context, $sticky);

            return self::emitFalse($context);
        }
        // PARTIAL substitutions leave sticky JSON_ERROR_* while still returning a string —
        // emit runtime set so last_error* observe it (#26792 / php-src json.c).
        if (0 !== $sticky) {
            self::emitSetLastError($context, $sticky);
        }

        return $context->builder->load($context->constantStringFromString($encoded));
    }

    /** Publish sticky/cleared JSON_ERROR_* into NestedJIT validate TU (#26792). */
    public static function emitSetLastError(Context $context, int $code): void
    {
        StringJsonDecode::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_json_set_last_error'),
            $context->getTypeFromString('int64')->constInt($code, false)
        );
    }

    /** @return Value __value__* bool false */
    private static function emitFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }

    /**
     * Resolve `json_encode(preg_split(…))` when pattern/subject(/limit/flags) are literals (#27080).
     */
    private static function tryCompileTimeArrayFromPregSplit(Block $block, Operand $operand): ?VmVariable
    {
        $parts = self::evalCompileTimePregSplit($block, $operand);
        if (null === $parts || false === $parts) {
            return null;
        }
        $ht = VmPreg::splitPartsToHashTable($parts, self::$lastPregSplitFlags);
        $var = new VmVariable();
        $var->array($ht);

        return $var;
    }

    /**
     * Resolve `json_encode(array_replace_recursive(…))` when all args are compile-time arrays (#26977).
     *
     * Uses VM {@see \PHPCompiler\VM\HashTable::replaceRecursiveCopy()} SSOT (php-src array.c).
     */
    private static function tryCompileTimeArrayFromArrayReplaceRecursive(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'array_replace_recursive');
        if (null === $args || [] === $args) {
            return null;
        }
        foreach ($args as $arg) {
            if (VmVariable::TYPE_ARRAY !== $arg->type) {
                return null;
            }
        }
        $first = $args[0]->toArray();
        $others = [];
        for ($i = 1, $n = \count($args); $i < $n; ++$i) {
            $others[] = $args[$i]->toArray();
        }
        $merged = $first->replaceRecursiveCopy(...$others);
        $var = new VmVariable();
        $var->array($merged);

        return $var;
    }

    /**
     * Resolve `json_encode(array_flip(…))` when the array arg is compile-time (#27072).
     *
     * Uses VM {@see VmArray::flip()} SSOT (php-src array.c). array_flip call-site LLVM
     * already builds the flipped map (foreach/dim green); NestedJIT json_encode still
     * exports string keys as empty (`{}`) without this fold.
     */
    private static function tryCompileTimeArrayFromArrayFlip(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'array_flip');
        if (null === $args || 1 !== \count($args)) {
            return null;
        }
        if (VmVariable::TYPE_ARRAY !== $args[0]->type) {
            return null;
        }
        $flipped = VmArray::flip($args[0]->toArray());
        $var = new VmVariable();
        $var->array($flipped);

        return $var;
    }

    private static int $lastPregSplitFlags = 0;

    /**
     * When preg_split constexpr-fails (false), json_encode soft-encodes boolean false.
     *
     * @return Value|null
     */
    private static function tryEncodePregSplitFalse(
        Context $context,
        Block $block,
        Operand $operand,
        int $flags
    ): ?Value {
        $parts = self::evalCompileTimePregSplit($block, $operand);
        if (false !== $parts) {
            return null;
        }
        // json_encode(false) with default flags → "false"
        $encoded = VmJsonFormat::encodeExported(false, $flags);
        if (false === $encoded) {
            return self::emitFalse($context);
        }

        return $context->builder->load($context->constantStringFromString($encoded));
    }

    /**
     * @return list<string>|list<array{0:string,1:int}>|false|null
     *         null = not a foldable preg_split result; false = PCRE error
     */
    private static function evalCompileTimePregSplit(Block $block, Operand $operand): array|false|null
    {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'preg_split');
        if (null === $args) {
            return null;
        }
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            return null;
        }
        if (VmVariable::TYPE_STRING !== $args[0]->type || VmVariable::TYPE_STRING !== $args[1]->type) {
            return null;
        }
        $pattern = $args[0]->toString();
        $subject = $args[1]->toString();
        $limit = -1;
        $flags = 0;
        if ($argc >= 3) {
            if (VmVariable::TYPE_INTEGER !== $args[2]->type) {
                return null;
            }
            $limit = $args[2]->toInt();
        }
        if (4 === $argc) {
            if (VmVariable::TYPE_INTEGER !== $args[3]->type) {
                return null;
            }
            $flags = $args[3]->toInt();
        }
        self::$lastPregSplitFlags = $flags;

        return VmPreg::pregSplit($pattern, $subject, $limit, $flags);
    }

    private static function followAssignSourceSlot(Block $block, int $slot): int
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type && $op->arg2 === $slot && null !== $op->arg3) {
                return self::followAssignSourceSlot($block, (int) $op->arg3);
            }
        }

        return $slot;
    }

    /**
     * @return list<VmVariable>|null
     */
    private static function literalArgsForFuncCallResult(Block $block, int $resultSlot, string $callee): ?array
    {
        $ops = $block->opCodes;
        $execIdx = null;
        foreach ($ops as $i => $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && $op->arg1 === $resultSlot) {
                $execIdx = $i;
                break;
            }
        }
        if (null === $execIdx) {
            return null;
        }
        $initIdx = null;
        for ($i = $execIdx - 1; $i >= 0; --$i) {
            if (OpCode::TYPE_FUNCCALL_INIT === $ops[$i]->type) {
                $initIdx = $i;
                break;
            }
            if (
                OpCode::TYPE_FUNCCALL_EXEC_RETURN === $ops[$i]->type
                || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $ops[$i]->type
            ) {
                return null;
            }
        }
        if (null === $initIdx) {
            return null;
        }
        $nameSlot = $ops[$initIdx]->arg1;
        if (null === $nameSlot || !isset($block->constants[$nameSlot])) {
            return null;
        }
        $nameVar = $block->constants[$nameSlot];
        if (VmVariable::TYPE_STRING !== $nameVar->type) {
            return null;
        }
        if (\strtolower($nameVar->toString()) !== \strtolower($callee)) {
            return null;
        }
        $args = [];
        for ($i = $initIdx + 1; $i < $execIdx; ++$i) {
            $op = $ops[$i];
            if (OpCode::TYPE_ARG_SEND !== $op->type) {
                continue;
            }
            if (null === $op->arg1) {
                return null;
            }
            if (isset($block->constants[$op->arg1])) {
                $copy = new VmVariable();
                $copy->copyFrom($block->constants[$op->arg1]);
                $args[] = $copy;
                continue;
            }
            // INIT_ARRAY slots (inline array literals) — same recovery as json_encode(lit) (#26977).
            $argOperand = $block->getOperand((int) $op->arg1);
            if (null === $argOperand) {
                return null;
            }
            $arr = CallUnpackHelper::tryCompileTimeArrayFromOperand($block, $argOperand);
            if (null === $arr) {
                return null;
            }
            $args[] = $arr;
        }

        return $args;
    }
}
