<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringChunkSplit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * chunk_split() — insert a separator every N bytes (subset of PHP).
 *
 * VM: {@see VmString::chunkSplit()}; JIT/AOT: native {@see StringChunkSplit} /
 * {@see \PHPCompiler\JIT\Builtin\ChunkSplitRuntime} {@code phpc_chunk_split_r1} (#36388;
 * NestedJIT ChunkSplitJitHelper leaked under thin AOT — peer str_pad_r1).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(chunk_split)
 */
final class chunk_split extends Internal
{
    public function __construct()
    {
        parent::__construct('chunk_split');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireArgCountRange($frame, 'chunk_split', 1, 3);
        $argc = \count($frame->calledArgs);
        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21190; reverts zparam TypeError).
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'chunk_split', 0, 'string');
        $length = 76;
        if ($argc >= 2) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'chunk_split', 2, 'length');
        }
        $separator = "\r\n";
        if (3 === $argc) {
            $separator = InternalStrictArg::resolveCoercibleStringArg($frame, 2, 'chunk_split', 'separator');
        }
        $result = VmString::chunkSplit($string, $length, $separator);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'chunk_split', 1, 3)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);
        $input = self::jitStringArg($context, $args[0], 0, 'string');
        $workBlock = BasicBlockHelper::append($context, 'chunksplit_call_work');
        $context->builder->branch($workBlock);
        $context->builder->positionAtEnd($workBlock);
        $i64 = $context->getTypeFromString('int64');
        $chunkLen = $i64->constInt(76, false);
        if ($argc >= 2) {
            JitInternalStrictArg::requireInt($context, $args[1], 'chunk_split', 'length', 2);
            $chunkLen = JitChunkSplit::lowerLengthArg($context, $args[1]);
            JitChunkSplit::emitRuntimeLengthGuard($context, $chunkLen);
        }
        if ($argc >= 3) {
            $separator = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[2], 'chunk_split', 2, 'separator');
        } else {
            $separator = $context->builder->load($context->constantStringFromString("\r\n"));
        }
        // Native phpc_chunk_split_r1 — no NestedJIT ChunkSplitJitHelper (#36388).
        $result = StringChunkSplit::invoke($context, $input, $chunkLen, $separator);
        JitStringBuiltinArg::releaseEphemeralArgAfterCopy($context, $args[0], $input);
        if ($argc >= 3) {
            JitStringBuiltinArg::releaseEphemeralArgAfterCopy($context, $args[2], $separator);
        }

        return $result;
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'chunk_split',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'chunk_split',
            $argIndex,
            $paramName
        );
    }
}
