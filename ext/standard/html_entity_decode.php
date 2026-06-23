<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * html_entity_decode() — decode HTML entities (subset; default ENT_COMPAT, issue #2472).
 */
final class html_entity_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('html_entity_decode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('html_entity_decode() requires one or two arguments in this compiler build');
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'html_entity_decode',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $flags = ENT_COMPAT;
        if ($argc >= 2) {
            $flags = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'html_entity_decode',
                2,
                'flags'
            );
        }
        $frame->returnVar->string(VmString::html_entity_decode($string, $flags));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('html_entity_decode() requires one or two arguments in this compiler build');
        }

        $literal = $args[0]->compileTimeString ?? null;
        $flags = ENT_COMPAT;
        $flagsKnown = $argc < 2;
        if ($argc >= 2) {
            $ct = self::tryCompileTimeFlags($context, $args[1]);
            if (null !== $ct) {
                $flags = $ct;
                $flagsKnown = true;
            }
        }
        if (null !== $literal && $flagsKnown) {
            return $context->builder->load(
                $context->constantStringFromString(
                    VmString::html_entity_decode($literal, $flags)
                )
            );
        }

        $str = JitStringBuiltinArg::lower($context, $args[0], 'html_entity_decode', 0, 'string');
        $i64 = $context->getTypeFromString('int64');
        $flagsVal = $i64->constInt($flags, false);
        if ($argc >= 2 && !$flagsKnown) {
            $flagsVal = JitLongArg::lower($context, $args[1], 'html_entity_decode() flags');
        }

        return JitHtmlEntityDecode::decode($context, $str, $flagsVal);
    }

    private static function tryCompileTimeFlags(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetZExtValue($arg->value->value);
            }
        }
        if (JITVariable::TYPE_VALUE === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            return null;
        }

        return null;
    }
}
