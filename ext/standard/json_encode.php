<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * json_encode() — native VmJson/VmJsonFormat (VM + JIT/AOT via __compiler_json_encode_value, #6852).
 *
 * php-src ref: ext/json/json.c — encode flags (#3281).
 */
final class json_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('json_encode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('json_encode() requires at least one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if ($argc > 2) {
            throw new \LogicException('json_encode() accepts at most two arguments');
        }
        $flags = self::resolveFlagsVm($frame, $argc);
        $ctx = $frame->vmContext;
        $vm = null !== $ctx ? $ctx->runtime->vm : null;
        try {
            $value = VmJson::export($frame->calledArgs[0]->resolveIndirect(), $ctx, $vm, $frame);
        } catch (VmJsonExportException $e) {
            VmJson::setLastError($e->errorCode);
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \JsonException(VmJson::lastErrorMsg(), $e->errorCode);
            }
            $frame->returnVar->bool(false);

            return;
        }
        $encoded = VmJsonFormat::encodeExported($value, $flags);
        if (false === $encoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($encoded);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('json_encode() requires at least one argument');
        }
        if (\count($args) > 2) {
            throw new \LogicException('json_encode() accepts at most two arguments');
        }

        $flagsVal = self::lowerFlagsJitValue($context, $args);
        $knownFlags = self::tryCompileTimeFlags($context, $args);
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $encoded = \json_encode($literal, $knownFlags ?? 0);
            if (false === $encoded) {
                if (VmJsonFlags::throwsOnError($knownFlags ?? 0)) {
                    throw new \JsonException(\json_last_error_msg(), \json_last_error());
                }
                throw new \LogicException('json_encode() failed');
            }

            return $context->builder->load($context->constantStringFromString($encoded));
        }
        if (null !== $knownFlags && 0 !== ($knownFlags & ~VmJsonFlags::ENCODE_SUPPORTED)) {
            throw new \LogicException('json_encode() flags not supported at runtime in this compiler build');
        }

        return JitJsonEncode::encode($context, $args[0], $flagsVal);
    }

    private static function resolveFlagsVm(Frame $frame, int $argc): int
    {
        if ($argc < 2) {
            return 0;
        }
        $flagsVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $flagsVar->type) {
            throw new \TypeError(
                'json_encode(): Argument #2 ($flags) must be of type int'
            );
        }

        return $flagsVar->toInt();
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function lowerFlagsJitValue(Context $context, array $args): Value
    {
        if (\count($args) < 2) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        return JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'json_encode', 2, 'flags');
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeFlags(Context $context, array $args): ?int
    {
        if (\count($args) < 2) {
            return 0;
        }
        $flagsArg = $args[1];
        if (JITVariable::TYPE_NATIVE_LONG !== $flagsArg->type || JITVariable::KIND_VALUE !== $flagsArg->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($flagsArg->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetZExtValue($flagsArg->value->value);
    }
}
