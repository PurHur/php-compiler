<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringParseStr;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;
use ArgumentCountError;

/**
 * parse_str() — query string parser (VM: ParseStrEngine; JIT compile-time: JitParseStrMaterializer; runtime: ParseStrRuntime).
 */
final class parse_str extends Internal
{
    public function __construct()
    {
        parent::__construct('parse_str');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new ArgumentCountError(\sprintf(
                'parse_str() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $encodedStr = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'parse_str', 0, 'string');

        $resultArg = $frame->calledArgs[1];
        $resolved = $resultArg->resolveIndirect();
        if (
            Variable::TYPE_ARRAY !== $resolved->type
            && Variable::TYPE_UNDEFINED !== $resolved->type
            && Variable::TYPE_NULL !== $resolved->type
        ) {
            throw new \TypeError(\sprintf(
                'parse_str(): Argument #2 ($result) must be of type array, %s given',
                VmParseStr::zendTypeLabel($resolved)
            ));
        }

        $params = ParseStrEngine::parse($encodedStr);
        $parsed = new \PHPCompiler\VM\HashTable();
        VmParseStr::mergeInto($parsed, $params);
        $replacement = new Variable(Variable::TYPE_ARRAY);
        $replacement->array($parsed);
        $resultArg->copyFrom($replacement);

        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('parse_str() expects exactly 2 arguments, %d given', \count($args))
            );

            return $context->getTypeFromString('int32')->constInt(0, false);
        }

        if (null === JitStringArg::compileTimeLiteral($args[0])) {
            StringParseStr::ensureLinked($context);
        }
        JitParseStr::parse($context, $args[0], $args[1]);

        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

        return $nullPtr;
    }
}
