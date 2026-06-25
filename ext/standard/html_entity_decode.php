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
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('html_entity_decode() requires one to three arguments in this compiler build');
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
        $encoding = 'UTF-8';
        if ($argc >= 2) {
            $flags = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'html_entity_decode',
                2,
                'flags'
            );
        }
        if ($argc >= 3) {
            $encoding = self::resolveEncodingVm($frame->calledArgs[2]->resolveIndirect());
        }
        $frame->returnVar->string(VmString::html_entity_decode($string, $flags, $encoding));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('html_entity_decode() requires one to three arguments in this compiler build');
        }

        $effectiveArgc = self::jitEffectiveArgc($argc, $args);
        if ($effectiveArgc >= 3) {
            return JitHtmlEntityDecode::decodeWithEncoding(
                $context,
                JitStringBuiltinArg::lower($context, $args[0], 'html_entity_decode', 0, 'string'),
                $argc >= 2
                    ? JitLongArg::lower($context, $args[1], 'html_entity_decode() flags')
                    : $context->getTypeFromString('int64')->constInt(ENT_COMPAT, false),
                JitStringBuiltinArg::lower($context, $args[2], 'html_entity_decode', 2, 'encoding')
            );
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

    private static function resolveEncodingVm(Variable $encVar): string
    {
        if (Variable::TYPE_NULL === $encVar->type) {
            return 'UTF-8';
        }
        if (Variable::TYPE_STRING !== $encVar->type) {
            throw new \TypeError(
                'html_entity_decode(): Argument #3 ($encoding) must be of type ?string, '
                .self::vmTypeName($encVar->type).' given'
            );
        }

        return $encVar->toString();
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function jitEffectiveArgc(int $argc, array $args): int
    {
        if ($argc >= 3 && self::encodingArgIsNull($args[2])) {
            return 2;
        }

        return $argc;
    }

    private static function encodingArgIsNull(JITVariable $var): bool
    {
        return JITVariable::TYPE_NULL === $var->type || $var->isNullConstant;
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'unknown type',
        };
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
