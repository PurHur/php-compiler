<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** define() — register a user constant at runtime (issue #204). */
final class define_ extends Internal
{
    public function __construct()
    {
        parent::__construct('define');
    }

    public function execute(Frame $frame): void
    {
        if (count($frame->calledArgs) < 2) {
            throw new \LogicException('define() requires at least two arguments');
        }
        $nameVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('define() constant name must be a string');
        }
        $value = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->vmContext) {
            throw new \LogicException('define() requires VM context');
        }
        $caseInsensitive = false;
        if (\count($frame->calledArgs) >= 3) {
            $caseInsensitive = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $ok = $frame->vmContext->defineConstant($nameVar->toString(), $value, $caseInsensitive);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('define() requires at least two arguments');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'define() constant name');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || null === $args[0]->compileTimeString) {
            throw new \LogicException('define() constant name must be a string literal in this compiler build');
        }
        $name = $args[0]->compileTimeString;
        $value = self::compileTimeVmVariable($context, $args[1]);
        $caseInsensitive = false;
        if (\count($args) >= 3) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[2]->type || null === $args[2]->value->value) {
                throw new \LogicException('define() case_insensitive must be a boolean literal in this compiler build');
            }
            $caseInsensitive = 0 !== (int) $context->llvm->lib->LLVMConstIntGetZExtValue($args[2]->value->value);
        }
        if (!$context->runtime->vmContext->defineConstant($name, $value, $caseInsensitive)) {
            throw new \LogicException("Cannot redefine constant {$name}");
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    private static function compileTimeVmVariable(Context $context, JITVariable $arg): Variable
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
            $phpVar = $context->runtime->vmContext->constantFetch($constName);
            if (null !== $phpVar) {
                return clone $phpVar;
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

        throw new \LogicException('define() value must be a compile-time constant in this compiler build');
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
        if (!$arg->value->isALoadInst()) {
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
}
