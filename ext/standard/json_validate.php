<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * json_validate() — PHP 8.3 syntax check without building values (issue #3101, #4085).
 *
 * VM: VmJsonScanner (ext/json parity subset); JIT/AOT: __compiler_json_validate (flags=0 runtime).
 * Supported $flags: JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE (php-src ext/json/php_json.c).
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
        $flags = self::resolveFlagsArg($context, $args);
        $depth = 512;
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            if (JITVariable::TYPE_NATIVE_LONG === $args[1]->type && JITVariable::KIND_VALUE === $args[1]->kind) {
                $depth = (int) $context->llvm->lib->LLVMConstIntGetZExtValue($args[1]->value->value);
                if ($depth < 1) {
                    throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
                }
            } else {
                return JitJsonValidate::invoke($context, $args[0], $args[1]);
            }
        }
        if (null !== $flags && 0 !== $flags) {
            VmJsonFlags::assertValidateFlags($flags);
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
            // Compile-time fold via VmJsonValidate (same depth rules as VM). last_error is not
            // updated at runtime for folded calls — AOT fixtures check the bool; VM/JIT cover
            // json_last_error_msg (#23007). Non-literal json always hits NestedJIT below.
            $ok = VmJsonValidate::validate($literal, $depth, $flags ?? 0);

            return $context->getTypeFromString('int1')->constInt($ok ? 1 : 0, false);
        }
        if (null !== $flags && 0 !== $flags) {
            throw new \LogicException('json_validate() flags not supported in this compiler build');
        }

        $jsonPtr = JsonStringOperandArg::jitJson($context, $args[0], 'json_validate');
        $depthConst = $context->getTypeFromString('int64')->constInt($depth, false);

        return JitJsonValidate::invokeWithDepth($context, $jsonPtr, $depthConst);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function resolveFlagsArg(Context $context, array $args): ?int
    {
        if (!isset($args[2]) || NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            return 0;
        }
        $flagsArg = $args[2];
        if (JITVariable::TYPE_NATIVE_LONG !== $flagsArg->type || JITVariable::KIND_VALUE !== $flagsArg->kind) {
            return null;
        }

        return (int) $context->llvm->lib->LLVMConstIntGetZExtValue($flagsArg->value->value);
    }
}
