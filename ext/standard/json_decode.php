<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * json_decode() — assoc arrays or stdClass object graphs (VM: VmJsonFormat; JIT/AOT: __compiler_json_decode).
 *
 * php-src ref: ext/json/php_json.c — depth, flags, JSON_THROW_ON_ERROR (#3267).
 */
final class json_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('json_decode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('json_decode() requires at least one argument');
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'json_decode() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        $json = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'json_decode',
            0,
            'json'
        );
        $assoc = false;
        if ($argc > 1) {
            $assocVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $assocVar->type) {
                throw new \TypeError('json_decode(): Argument #2 ($assoc) must be of type bool');
            }
            $assoc = $assocVar->toBool();
        }
        $depth = 512;
        if ($argc > 2) {
            $depth = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'json_decode',
                3,
                'depth'
            );
            if ($depth < 1) {
                throw new \ValueError('json_decode(): Argument #3 ($depth) must be greater than 0');
            }
        }
        $flags = 0;
        if ($argc > 3) {
            $flags = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[3]->resolveIndirect(),
                'json_decode',
                4,
                'flags'
            );
        }
        $decoded = VmJsonFormat::decode($json, $assoc, $depth, $flags);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('json_decode() requires VM context in this compiler build');
        }
        $frame->returnVar->copyFrom(VmJson::importDecoded($decoded, $assoc, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \LogicException('json_decode() requires at least one argument');
        }
        if ($argc > 4) {
            throw new \LogicException('json_decode() expects at most 4 arguments');
        }

        $assoc = self::resolveAssocFlag($context, $args);
        $depth = self::resolveDepthJit($context, $args, $argc);
        $flags = self::resolveFlagsJit($context, $args, $argc);
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $decoded = VmJsonFormat::decode($literal, $assoc, $depth, $flags);

            return JitJsonDecode::materializeDecoded($context, $decoded, $assoc);
        }

        if (512 !== $depth || 0 !== $flags) {
            throw new \LogicException('json_decode() depth/flags not supported at runtime in this compiler build');
        }

        if ($assoc) {
            return JitJsonDecode::decodeRuntime($context, $args[0]);
        }

        // assoc=false runtime path is unsupported; still reject enum operands first (#5907).
        JitStringBuiltinArg::lower($context, $args[0], 'json_decode', 0, 'json');

        return JitJsonDecode::decodeRuntimeObjectMode($context, $args[0]);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function resolveAssocFlag(Context $context, array $args): bool
    {
        if (\count($args) < 2) {
            return false;
        }
        $assoc = self::compileTimeBool($context, $args[1]);
        if (null !== $assoc) {
            return $assoc;
        }

        throw new \LogicException('json_decode() assoc flag must be a compile-time boolean in this compiler build');
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function resolveDepthJit(Context $context, array $args, int $argc): int
    {
        if ($argc <= 2) {
            return 512;
        }
        $depth = self::compileTimeInt($context, $args[2]);
        if (null === $depth) {
            throw new \LogicException('json_decode() depth must be a compile-time integer in this compiler build');
        }
        if ($depth < 1) {
            throw new \ValueError('json_decode(): Argument #3 ($depth) must be greater than 0');
        }

        return $depth;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function resolveFlagsJit(Context $context, array $args, int $argc): int
    {
        if ($argc <= 3) {
            return 0;
        }
        $flags = self::compileTimeInt($context, $args[3]);
        if (null === $flags) {
            throw new \LogicException('json_decode() flags must be a compile-time integer in this compiler build');
        }

        return $flags;
    }

    private static function compileTimeBool(Context $context, JITVariable $var): ?bool
    {
        if (JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type && $var->value->isAConstantInt()) {
            return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $var->type
            && null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        return null;
    }

    private static function compileTimeInt(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $var->type || JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($var->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
    }
}
