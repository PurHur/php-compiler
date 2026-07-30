<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringParseStr;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;
use ArgumentCountError;

/**
 * parse_str() — query string parser (VM: ParseStrEngine; JIT compile-time: JitParseStrMaterializer; runtime: ParseStrRuntime).
 *
 * php-src arity is exactly 2 on all versions (basic_functions.stub.php). The PROFILE=8.4
 * `$separator` third parameter from #17320 was a phantom API and is gated off (#23949).
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
        if ($argc !== 2) {
            throw new ArgumentCountError(\sprintf(
                'parse_str() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        // Soft-null DEP+coerce on 8.4 (php-src string.c Z_PARAM_STR; #21480, reverts #21380 TypeError).
        $encodedStr = VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'parse_str',
            0,
            'string'
        );

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

        $params = ParseStrEngine::parse($encodedStr, '&');
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
        $argc = \count($args);
        if ($argc !== 2) {
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('parse_str() expects exactly 2 arguments, %d given', $argc)
            );

            return $context->getTypeFromString('int32')->constInt(0, false);
        }

        if (null === JitStringArg::compileTimeLiteral($args[0])) {
            StringParseStr::ensureLinked($context);
        }
        JitParseStr::parse($context, $args[0], $args[1], null);

        $nullSlot = \PHPCompiler\JIT\JitValueBox::alloc($context);
        $nullPtr = \PHPCompiler\JIT\JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

        return $nullPtr;
    }
}
