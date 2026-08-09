<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
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
        // php-src ext/json/php_json.c — ArgumentCountError (#28474).
        $this->requireArgCountRange($frame, 'json_decode', 1, 4);
        $json = JsonStringOperandArg::vmJson($frame, 'json_decode');
        $depth = 512;
        if (isset($frame->calledArgs[2])) {
            $depth = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                2,
                'json_decode',
                3,
                'depth'
            );
            if ($depth < 1) {
                throw new \ValueError('json_decode(): Argument #3 ($depth) must be greater than 0');
            }
        }
        $flags = 0;
        if (isset($frame->calledArgs[3])) {
            $flags = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                3,
                'json_decode',
                4,
                'flags'
            );
        }
        $assoc = self::resolveEffectiveAssocVm($frame, $flags);
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
        if (!$this->requireArgCountRangeJit($context, $args, 'json_decode', 1, 4)) {
            return JitJsonDecode::materializeNull($context);
        }
        $depth = self::resolveDepthJit($context, $args);
        $flags = self::resolveFlagsJit($context, $args);
        $assoc = self::resolveAssocFlag($context, $args, $flags);
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (
            null === $literal
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
        ) {
            if ($context->callerStrictTypes) {
                JsonStringOperandArg::jitJson($context, $args[0], 'json_decode');

                return JitJsonDecode::materializeNull($context);
            }
            JitStringBuiltinArg::emitNullStringParamDeprecation($context, 'json_decode', 0, 'json');
            $literal = '';
        }
        if (null !== $literal) {
            try {
                $decoded = VmJsonFormat::decode($literal, $assoc, $depth, $flags);
            } catch (\JsonException $e) {
                // Compile-time THROW fold → runtime catchable JsonException (#27623).
                return JitJsonThrow::emitFromException($context, $e);
            }
            // Soft-fail sticky last_error (invalid literal without THROW) (#27623 / #26792).
            $sticky = VmJson::lastError();
            if (0 !== $sticky && !VmJsonFlags::throwsOnError($flags)) {
                JitJsonEncodeCompileTime::emitSetLastError($context, $sticky);
            }

            return JitJsonDecode::materializeDecoded($context, $decoded, $assoc);
        }

        if (512 !== $depth || 0 !== $flags) {
            throw new \LogicException('json_decode() depth/flags not supported at runtime in this compiler build');
        }

        if ($assoc) {
            return JitJsonDecode::decodeRuntime($context, $args[0]);
        }

        // assoc=false runtime path is unsupported; soft-null / enum TypeError still apply first (#5907, #18665, #21223).
        JsonStringOperandArg::jitJson($context, $args[0], 'json_decode');

        return JitJsonDecode::decodeRuntimeObjectMode($context, $args[0]);
    }

    /**
     * php-src ext/json/php_json.c — $assoc null uses JSON_OBJECT_AS_ARRAY (#11778).
     */
    private static function resolveEffectiveAssocVm(Frame $frame, int $flags): bool
    {
        if (!isset($frame->calledArgs[1])) {
            return false;
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return VmJsonFlags::objectAsArray($flags);
        }
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireBool($frame, 1, 'json_decode', 'assoc')->toBool();
        }

        return VmMath::parseBoolBuiltinArg(
            $frame->calledArgs[1],
            'json_decode',
            2,
            'assoc'
        );
    }

    private static function resolveAssocFlag(Context $context, array $args, int $flags): bool
    {
        if (!isset($args[1]) || NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            return false;
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            return VmJsonFlags::objectAsArray($flags);
        }
        // ConstFetch null is a module-global load — prefer folded name (#27623 / #24137).
        if (null !== $args[1]->compileTimeConstantName
            && 'null' === strtolower($args[1]->compileTimeConstantName)
        ) {
            return VmJsonFlags::objectAsArray($flags);
        }
        if ($context->callerStrictTypes) {
            JitInternalStrictArg::requireBool($context, $args[1], 'json_decode', 'assoc', 2);
        }
        $assoc = self::compileTimeBool($context, $args[1]);
        if (null !== $assoc) {
            return $assoc;
        }
        $int = self::compileTimeInt($context, $args[1]);
        if (null !== $int) {
            return 0 !== $int;
        }

        throw new \LogicException('json_decode() assoc flag must be a compile-time boolean in this compiler build');
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function resolveDepthJit(Context $context, array $args): int
    {
        if (!isset($args[2]) || NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            return 512;
        }
        if ($context->callerStrictTypes) {
            JitInternalStrictArg::requireInt($context, $args[2], 'json_decode', 'depth', 3);
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
    private static function resolveFlagsJit(Context $context, array $args): int
    {
        if (!isset($args[3]) || NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            return 0;
        }
        if ($context->callerStrictTypes) {
            JitInternalStrictArg::requireInt($context, $args[3], 'json_decode', 'flags', 4);
        }
        $flags = self::compileTimeInt($context, $args[3]);
        if (null === $flags) {
            throw new \LogicException('json_decode() flags must be a compile-time integer in this compiler build');
        }

        return $flags;
    }

    private static function compileTimeBool(Context $context, JITVariable $var): ?bool
    {
        // ConstFetch true/false is loaded from a module global — LLVMIsAConstantInt
        // sees the Load, not the initializer. Prefer the folded name (#24137).
        if (null !== $var->compileTimeConstantName) {
            $name = strtolower($var->compileTimeConstantName);
            if ('true' === $name) {
                return true;
            }
            if ('false' === $name) {
                return false;
            }
        }
        if (null !== $var->compileTimeLong) {
            return 0 !== $var->compileTimeLong;
        }
        if (null === $var->value) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type
            && null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $var->type
            && null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        return null;
    }

    /**
     * Resolve a compile-time int for depth/flags (#27623).
     *
     * Named JSON_* ConstFetch lowers as a load from a module global — LLVMIsAConstantInt
     * sees the Load, not the initializer. Prefer {@see JITVariable::$compileTimeLong} /
     * {@see JITVariable::$compileTimeConstantName} (same shape as preg_split #27647).
     */
    private static function compileTimeInt(Context $context, JITVariable $var): ?int
    {
        if (null !== ($var->compileTimeLong ?? null)) {
            return (int) $var->compileTimeLong;
        }
        $constName = $var->compileTimeConstantName ?? null;
        if (null !== $constName) {
            $lookup = strtolower($constName);
            if (isset(StdlibConstants::CORE_INT_BY_NAME[$lookup])) {
                return StdlibConstants::CORE_INT_BY_NAME[$lookup];
            }
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
        // JSON_* globals: load operand → registered constant name (#21723 / encode peer).
        if (
            JITVariable::TYPE_NATIVE_LONG === $var->type
            && JITVariable::KIND_VALUE === $var->kind
            && null !== $var->value
        ) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
            }
            if (null !== $lib->LLVMIsALoadInst($var->value->value)) {
                $ptr = $var->value->getOperand(0);
                $name = $lib->LLVMGetValueName($ptr->value)?->toString() ?? '';
                if ('' !== $name && isset($context->constants[$name])) {
                    if ($context->constants[$name][0] === $var->type) {
                        $phpVar = $context->runtime->vmContext?->constantFetch($name);
                        if (null !== $phpVar && Variable::TYPE_INTEGER === $phpVar->type) {
                            return $phpVar->toInt();
                        }
                    }
                }
            }
        }

        return null;
    }
}
