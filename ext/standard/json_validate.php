<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * json_validate() — PHP 8.3 syntax check without building values (issue #3101, #4085, #29069).
 *
 * VM: VmJsonScanner; JIT/AOT: __compiler_json_validate(json, depth, flags).
 * Supported $flags: exactly 0 or JSON_INVALID_UTF8_IGNORE (php-src ext/json/json.c).
 */
final class json_validate extends Internal
{
    public function __construct()
    {
        parent::__construct('json_validate');
    }

    public function execute(Frame $frame): void
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \LogicException('json_validate() requires at least one argument');
        }
        // Sparse named optionals (e.g. flags: without depth) — isset, not count (#23876 / #10032).
        foreach (\array_keys($frame->calledArgs) as $idx) {
            if ($idx < 0 || $idx > 2) {
                throw new \ArgumentCountError(\sprintf(
                    'json_validate() expects at most 3 arguments, %d given',
                    $idx + 1
                ));
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $json = JsonStringOperandArg::vmJson($frame, 'json_validate');
        $depth = 512;
        if (isset($frame->calledArgs[1])) {
            $depthVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $depthVar->type) {
                throw new \LogicException('json_validate() argument #2 must be an integer in this compiler build');
            }
            $depth = $depthVar->toInt();
        }
        $flags = 0;
        if (isset($frame->calledArgs[2])) {
            $flagsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('json_validate() argument #3 must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        $frame->returnVar->bool(VmJsonValidate::validate($json, $depth, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \LogicException('json_validate() requires at least one argument');
        }
        if ($argc > 3) {
            throw new \LogicException('json_validate() accepts at most three arguments');
        }
        $flagsConst = self::resolveFlagsArg($args);
        $hasRuntimeFlags = isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2]) && null === $flagsConst;
        $depth = 512;
        $depthIsConst = true;
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            $depthConst = self::compileTimeLong($args[1]);
            if (null !== $depthConst) {
                $depth = $depthConst;
                if ($depth < 1) {
                    throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
                }
            } else {
                $depthIsConst = false;
            }
        }
        // Compile-time flags: Zend exact 0|IGNORE — emit catchable ValueError (#29069).
        if (null !== $flagsConst && !VmJsonFlags::isValidValidateFlags($flagsConst)) {
            ExceptionBridge::emitValueErrorAndAbort($context, VmJsonFlags::VALIDATE_FLAGS_ERROR);
            // Catchable throw terminates the block; open a dead continuation for the return slot.
            BasicBlockHelper::ensureOpenInsertBlock($context, 'json_validate_bad_flags_dead');

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        // Runtime depth and/or flags → NestedJIT (ABI includes flags).
        if (!$depthIsConst || $hasRuntimeFlags) {
            $flagsArg = (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2]))
                ? $args[2]
                : null;
            if ($depthIsConst) {
                $jsonPtr = JsonStringOperandArg::jitJson($context, $args[0], 'json_validate');
                $depthVal = $context->getTypeFromString('int64')->constInt($depth, false);
                $flagsVal = null === $flagsArg
                    ? $context->getTypeFromString('int64')->constInt($flagsConst ?? 0, false)
                    : JitLongArg::lower($context, $flagsArg, 'json_validate() argument #3');

                return JitJsonValidate::invokeWithDepth($context, $jsonPtr, $depthVal, $flagsVal);
            }

            return JitJsonValidate::invoke($context, $args[0], $args[1], $flagsArg);
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        // Null constant: soft-null DEP + coerce to '' then fold (Zend 8.4 / #28333; same as json_decode).
        if (
            null === $literal
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
        ) {
            if ($context->callerStrictTypes) {
                JsonStringOperandArg::jitJson($context, $args[0], 'json_validate');

                return $context->getTypeFromString('int1')->constInt(0, false);
            }
            JitStringBuiltinArg::emitNullStringParamDeprecation($context, 'json_validate', 0, 'json');
            $literal = '';
        }
        if (null !== $literal) {
            // Compile-time fold via VmJsonValidate (same depth rules as VM).
            $ok = VmJsonValidate::validate($literal, $depth, $flagsConst ?? 0);

            return $context->getTypeFromString('int1')->constInt($ok ? 1 : 0, false);
        }

        $jsonPtr = JsonStringOperandArg::jitJson($context, $args[0], 'json_validate');
        $depthVal = $context->getTypeFromString('int64')->constInt($depth, false);
        $flagsVal = $context->getTypeFromString('int64')->constInt($flagsConst ?? 0, false);

        return JitJsonValidate::invokeWithDepth($context, $jsonPtr, $depthVal, $flagsVal);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function resolveFlagsArg(array $args): ?int
    {
        if (!isset($args[2]) || NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            return 0;
        }

        return self::compileTimeLong($args[2]);
    }

    private static function compileTimeLong(JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $const = $arg->value;
        if ($const instanceof Value && $const->isConstant()) {
            return (int) $const->constInt();
        }

        return null;
    }
}
