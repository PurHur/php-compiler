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
 * json_validate() — PHP 8.3 syntax check without building values (issue #3101).
 *
 * VM: host json_validate(); JIT/AOT: __compiler_json_validate (ext/json parity subset).
 * Unsupported $flags throw LogicException; depth ValueError on VM when nesting exceeds limit.
 */
final class json_validate extends Internal
{
    public function __construct()
    {
        parent::__construct('json_validate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('json_validate() requires at least one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $jsonVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $jsonVar->type) {
            throw new \LogicException('json_validate() argument #1 must be a string in this compiler build');
        }
        $depth = 512;
        if ($argc > 1) {
            $depthVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $depthVar->type) {
                throw new \LogicException('json_validate() argument #2 must be an integer in this compiler build');
            }
            $depth = $depthVar->toInt();
        }
        if ($argc > 2) {
            $flagsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('json_validate() argument #3 must be an integer in this compiler build');
            }
            if (0 !== $flagsVar->toInt()) {
                throw new \LogicException('json_validate() flags not supported in this compiler build');
            }
        }
        if ($argc > 3) {
            throw new \LogicException('json_validate() accepts at most three arguments');
        }
        $frame->returnVar->bool(VmJsonValidate::validate($jsonVar->toString(), $depth));
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
        if ($argc > 2) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type
                || JITVariable::KIND_VALUE !== $args[2]->kind
                || 0 !== (int) $context->llvm->lib->LLVMConstIntGetZExtValue($args[2]->value->value)) {
                throw new \LogicException('json_validate() flags not supported in this compiler build');
            }
        }
        $depth = 512;
        if ($argc > 1) {
            if (JITVariable::TYPE_NATIVE_LONG === $args[1]->type && JITVariable::KIND_VALUE === $args[1]->kind) {
                $depth = (int) $context->llvm->lib->LLVMConstIntGetZExtValue($args[1]->value->value);
                if ($depth < 1) {
                    throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
                }
            } else {
                return JitJsonValidate::invoke($context, $args[0], $args[1]);
            }
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $ok = VmJsonValidate::validate($literal, $depth);

            return $context->getTypeFromString('int1')->constInt($ok ? 1 : 0, false);
        }

        $jsonPtr = JitStringArg::lower($context, $args[0], 'json_validate() argument #1');
        $depthConst = $context->getTypeFromString('int64')->constInt($depth, false);

        return JitJsonValidate::invokeWithDepth($context, $jsonPtr, $depthConst);
    }
}
