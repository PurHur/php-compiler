<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * define() — register a user constant at runtime (issue #204, JIT #4435).
 *
 * Excess argc (>3) → Zend ArgumentCountError (#30573; php-src Zend/zend_builtin_functions.c).
 * The 3rd `$case_insensitive` parameter remains arity-legal (deprecated/ignored).
 * Under strict_types, null for `$case_insensitive` → TypeError (#31406; Z_PARAM_BOOL).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(define)
 * php-src: Zend/zend_builtin_functions.stub.php — define(string, mixed, bool = false): bool
 */
final class define_ extends Internal
{
    public const MSG_CASE_INSENSITIVE_IGNORED =
        'define(): Argument #3 ($case_insensitive) is ignored since declaration of case-insensitive constants is no longer supported';

    public const MSG_CLASS_CONSTANT =
        'define(): Argument #1 ($constant_name) cannot be a class constant';

    public function __construct()
    {
        parent::__construct('define');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'define() expects at most 3 arguments, '.$argc.' given'
            );
        }
        if ($argc < 2) {
            throw new \LogicException('define() requires at least two arguments');
        }
        $name = self::vmConstantNameArg($frame);
        self::rejectClassConstantName($name);
        $value = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->vmContext) {
            throw new \LogicException('define() requires VM context');
        }
        // Z_PARAM_BOOL $case_insensitive — strict TypeError; soft-null DEP+coerce (#31406).
        $caseInsensitive = false;
        if ($argc >= 3) {
            $caseInsensitive = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                2,
                'define',
                3,
                'case_insensitive'
            );
        }
        if ($caseInsensitive) {
            $file = '' !== $frame->scriptPath ? $frame->scriptPath : null;
            $frame->vmContext->errors->triggerError(
                self::MSG_CASE_INSENSITIVE_IGNORED,
                ErrorReporter::E_WARNING,
                $file,
                $frame->vmContext,
                $frame
            );
        }
        $filename = '' !== $frame->scriptPath ? $frame->scriptPath : 'Command line code';
        $ok = $frame->vmContext->defineConstant($name, $value, false, $filename);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 3) {
            // Catchable ArgumentCountError under AOT try/catch (#30573).
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'define() expects at most 3 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if ($argc < 2) {
            throw new \LogicException('define() requires at least two arguments');
        }
        if ($argc >= 3) {
            // Compile-time null under strict: catchable TypeError then stop (#31406 / peer md5 #31358).
            if ($context->callerStrictTypes && (
                JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)
            )) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'define(): Argument #3 ($case_insensitive) must be of type bool, null given'
                );
                $slot = JitValueBox::alloc($context);

                return JitValueBox::pointer($context, $slot);
            }
            // Z_PARAM_BOOL: strict TypeError on null; else null→false + E_DEPRECATED (#31406).
            JitBoolArg::lowerCoerceZParamBool(
                $context,
                $args[2],
                'define',
                'case_insensitive',
                3
            );
        }

        return JitDefine::invoke($context, $args[0], $args[1]);
    }

    /** Z_PARAM_STR — soft-null DEP+coerce on 8.4 (#21281, ext/standard/basic_functions.c). */
    private static function vmConstantNameArg(Frame $frame): string
    {
        return VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'define',
            0,
            'constant_name'
        );
    }

    public static function invokeLiteral(Context $context, string $name, JITVariable $valueArg): Value
    {
        $value = self::compileTimeVmVariable($context, $valueArg);
        if (null === $value) {
            throw new \LogicException('define() value must be a compile-time constant in this compiler build');
        }

        return self::invokeLiteralWithValue($context, $name, $value);
    }

    public static function invokeLiteralWithValue(Context $context, string $name, Variable $value): Value
    {
        if (str_contains($name, '::')) {
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitValueError($context, self::MSG_CLASS_CONSTANT);
            $context->builder->call($context->lookupFunction('abort'));
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $ok = true;
        if (null !== $context->runtime->vmContext) {
            $ok = $context->runtime->vmContext->defineConstant($name, $value);
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($ok ? 1 : 0, false);
    }

    public static function tryCompileTimeVmVariable(Context $context, JITVariable $arg): ?Variable
    {
        return self::compileTimeVmVariable($context, $arg);
    }

    private static function compileTimeVmVariable(Context $context, JITVariable $arg): ?Variable
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return new Variable(Variable::TYPE_NULL);
        }
        if (JITVariable::TYPE_VALUE === $arg->type && $arg->isNullConstant) {
            return new Variable(Variable::TYPE_NULL);
        }
        $literal = $arg->compileTimeString ?? null;
        if (JITVariable::TYPE_STRING === $arg->type && null !== $literal) {
            $vm = new Variable(Variable::TYPE_STRING);
            $vm->string($literal);

            return $vm;
        }
        $constName = $arg->compileTimeConstantName ?? null;
        if (null !== $constName) {
            if (null !== $context->runtime->vmContext) {
                $phpVar = $context->runtime->vmContext->constantFetch($constName);
                if (null !== $phpVar) {
                    return clone $phpVar;
                }
            }
            $phpVar = self::compileTimeVmVariableFromCoreConstantName($constName);
            if (null !== $phpVar) {
                return $phpVar;
            }
        }
        if (JITVariable::KIND_VALUE === $arg->kind) {
            $fromConst = self::compileTimeVmVariableFromLlvmConstant($context, $arg);
            if (null !== $fromConst) {
                return $fromConst;
            }
            $fromGlobal = self::compileTimeVmVariableFromRegisteredGlobal($context, $arg);
            if (null !== $fromGlobal) {
                return $fromGlobal;
            }
        }

        return null;
    }

    private static function compileTimeVmVariableFromCoreConstantName(string $name): ?Variable
    {
        switch (strtolower($name)) {
            case 'null':
                return new Variable(Variable::TYPE_NULL);
            case 'true':
                $vm = new Variable(Variable::TYPE_BOOLEAN);
                $vm->bool(true);

                return $vm;
            case 'false':
                $vm = new Variable(Variable::TYPE_BOOLEAN);
                $vm->bool(false);

                return $vm;
            default:
                return null;
        }
    }

    private static function compileTimeVmVariableFromLlvmConstant(Context $context, JITVariable $arg): ?Variable
    {
        $lib = $context->llvm->lib;
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
                    return null;
                }
                $vm = new Variable(Variable::TYPE_INTEGER);
                $vm->int((int) $lib->LLVMConstIntGetZExtValue($arg->value->value));

                return $vm;
            case JITVariable::TYPE_NATIVE_BOOL:
                if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
                    return null;
                }
                $vm = new Variable(Variable::TYPE_BOOLEAN);
                $vm->bool(0 !== (int) $lib->LLVMConstIntGetZExtValue($arg->value->value));

                return $vm;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                if (!$arg->value->isAConstantFP()) {
                    return null;
                }
                $losesInfo = $lib->FFI->new('bool');
                $vm = new Variable(Variable::TYPE_FLOAT);
                $vm->float($lib->LLVMConstRealGetDouble($arg->value->value, $losesInfo));

                return $vm;
            default:
                return null;
        }
    }

    private static function compileTimeVmVariableFromRegisteredGlobal(Context $context, JITVariable $arg): ?Variable
    {
        if (null === $arg->value) {
            return null;
        }
        try {
            if (!$arg->value->isALoadInst()) {
                return null;
            }
        } catch (\TypeError) {
            return null;
        }
        $ptr = $arg->value->getOperand(0);
        $name = (string) $context->llvm->lib->LLVMGetValueName($ptr->value);
        if ('' === $name || !isset($context->constants[$name])) {
            return null;
        }
        if ($context->constants[$name][0] !== $arg->type) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($name);
        if (null === $phpVar) {
            return null;
        }

        return clone $phpVar;
    }

    /**
     * php-src: ext/standard/basic_functions.c — define() rejects Class::CONST names.
     */
    private static function rejectClassConstantName(string $name): void
    {
        if (str_contains($name, '::')) {
            throw new \ValueError(self::MSG_CLASS_CONSTANT);
        }
    }
}
