<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * json_encode() — native VmJson/VmJsonFormat (VM + JIT/AOT via JsonEncodeJitHelper, #9267).
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
        // php-src ext/json/json.c — ArgumentCountError (#21964).
        $this->requireArgCountRange($frame, 'json_encode', 1, 3);
        if (null === $frame->returnVar) {
            return;
        }
        $flags = self::resolveFlagsVm($frame);
        $maxDepth = self::resolveDepthVm($frame);
        $ctx = $frame->vmContext;
        $vm = null !== $ctx ? $ctx->runtime->vm : null;
        try {
            $value = VmJson::export(
                $frame->calledArgs[0]->resolveIndirect(),
                $ctx,
                $vm,
                $frame,
                $maxDepth
            );
        } catch (VmJsonExportException $e) {
            // php-src: THROW without PARTIAL leaves JSON_G(error_code) unchanged (#25456).
            if (
                VmJsonFlags::throwsOnError($flags)
                && !VmJsonFlags::partialOutputOnError($flags)
            ) {
                VmJson::throwExceptionPreservingLastError($e->errorCode);
            }
            VmJson::setLastError($e->errorCode);
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \JsonException(VmJson::lastErrorMsg(), $e->errorCode);
            }
            $frame->returnVar->bool(false);

            return;
        }
        $encoded = VmJsonFormat::encodeExported($value, $flags, $maxDepth);
        if (false === $encoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($encoded);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'json_encode', 1, 3)) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        // Resolve compile-time flags before lowerIntBuiltinArg mutates the arg shape.
        $knownFlags = self::tryCompileTimeFlags($context, $args);
        $flagsVal = self::lowerFlagsJitValue($context, $args);
        // Arrays / stdClass with literal props before string fold — object temps stash
        // class names in compileTimeString (#26872) and would fold to "\"stdClass\"" (#28638).
        $arrayLiteral = JitJsonEncodeCompileTime::tryEncode(
            $context,
            $context->jitEnclosingBlock,
            $context->jitJsonEncodeValueOperand,
            $knownFlags ?? 0
        );
        if (null !== $arrayLiteral) {
            return $arrayLiteral;
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal && (
            JITVariable::TYPE_OBJECT === $args[0]->type
            || null !== ($args[0]->classUserType ?? null)
            || JitJsonEncodeCompileTime::operandIsNewObject(
                $context->jitEnclosingBlock,
                $context->jitJsonEncodeValueOperand
            )
        )) {
            $literal = null;
        }
        if (null !== $literal && null !== $knownFlags) {
            // PHP-in-PHP fold — same encoder as VM/runtime (#21723); avoid host ext/json skew.
            try {
                $encoded = VmJsonFormat::encodeExported($literal, $knownFlags);
            } catch (\JsonException $e) {
                // Compile-time THROW fold → runtime catchable JsonException (#27623).
                return JitJsonThrow::emitFromException($context, $e);
            }
            $sticky = VmJson::lastError();
            if (false === $encoded) {
                if (VmJsonFlags::throwsOnError($knownFlags)) {
                    // encodeExported throws on THROW; false is soft-failure only.
                    throw new \LogicException('json_encode() THROW path returned false');
                }
                // Soft-fail + sticky last_error at runtime (#26792).
                JitJsonEncodeCompileTime::emitSetLastError($context, $sticky);
                $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                \PHPCompiler\JIT\JitValueBox::writeBool(
                    $context,
                    $slot,
                    $context->constantFromBool(false)
                );

                return \PHPCompiler\JIT\JitValueBox::pointer($context, $slot);
            }
            if (0 !== $sticky) {
                JitJsonEncodeCompileTime::emitSetLastError($context, $sticky);
            }

            return $context->builder->load($context->constantStringFromString($encoded));
        }
        if (null !== $knownFlags && 0 !== ($knownFlags & ~VmJsonFlags::ENCODE_SUPPORTED)) {
            throw new \LogicException('json_encode() flags not supported at runtime in this compiler build');
        }

        return JitJsonEncode::encode($context, $args[0], $flagsVal);
    }

    private static function resolveFlagsVm(Frame $frame): int
    {
        if (!isset($frame->calledArgs[1])) {
            return 0;
        }

        return VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            1,
            'json_encode',
            2,
            'flags'
        );
    }

    private static function resolveDepthVm(Frame $frame): int
    {
        if (!isset($frame->calledArgs[2])) {
            return 512;
        }
        $depth = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[2]->resolveIndirect(),
            'json_encode',
            3,
            'depth'
        );
        if ($depth < 1) {
            throw new \ValueError('json_encode(): Argument #3 ($depth) must be greater than 0');
        }

        return $depth;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function lowerFlagsJitValue(Context $context, array $args): Value
    {
        if (!isset($args[1]) || NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        return JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'json_encode', 2, 'flags');
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeFlags(Context $context, array $args): ?int
    {
        if (!isset($args[1]) || NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            return 0;
        }
        $flagsArg = $args[1];
        // Prefer folded metadata — ConstFetch JSON_* is a Load, not ConstantInt (#27623).
        if (null !== ($flagsArg->compileTimeLong ?? null)) {
            return (int) $flagsArg->compileTimeLong;
        }
        $constName = $flagsArg->compileTimeConstantName ?? null;
        if (null !== $constName) {
            $jsonFlags = VmJsonFlags::constants();
            if (isset($jsonFlags[$constName])) {
                return $jsonFlags[$constName];
            }
            if (isset($jsonFlags[strtoupper($constName)])) {
                return $jsonFlags[strtoupper($constName)];
            }
            if (null !== $context->runtime->vmContext) {
                $phpVar = $context->runtime->vmContext->constantFetch($constName);
                if (null !== $phpVar && Variable::TYPE_INTEGER === $phpVar->type) {
                    return $phpVar->toInt();
                }
            }
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $flagsArg->type || JITVariable::KIND_VALUE !== $flagsArg->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null !== $lib->LLVMIsAConstantInt($flagsArg->value->value)) {
            return (int) $lib->LLVMConstIntGetZExtValue($flagsArg->value->value);
        }
        // JSON_* constants lower as loads from registered globals (define_.php peer).
        // Avoid Value::isALoadInst() — php-llvm fromBool TypeError on LLVMIsALoadInst (#21723).
        if (null === $flagsArg->value || null === $lib->LLVMIsALoadInst($flagsArg->value->value)) {
            return null;
        }
        $ptr = $flagsArg->value->getOperand(0);
        $name = $lib->LLVMGetValueName($ptr->value)?->toString() ?? '';
        if ('' === $name || !isset($context->constants[$name])) {
            return null;
        }
        if ($context->constants[$name][0] !== $flagsArg->type) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($name);
        if (null === $phpVar || Variable::TYPE_INTEGER !== $phpVar->type) {
            return null;
        }

        return $phpVar->toInt();
    }
}
