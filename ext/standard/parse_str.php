<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\StringParseStr;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * parse_str() — query string parser (VM: ParseStrEngine; JIT/AOT: StringParseStrJit / __compiler_parse_str).
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
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('parse_str() requires one or two arguments in this compiler build');
        }
        $encodedStr = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'parse_str', 0, 'string');
        if (1 === $argc) {
            $caller = VmScope::requireCaller($frame);
            VmScope::requireMainScriptForParseStrOneArg($caller);
            $params = ParseStrEngine::parse($encodedStr);
            VmParseStr::importIntoCaller($caller, $params);

            return;
        }

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
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        StringParseStr::ensureLinked($context);
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('parse_str() requires one or two arguments in this compiler build');
        }
        if (1 === \count($args)) {
            $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
            if ($block instanceof Block && !$block->isMainScript()) {
                TypeErrorRaise::registerDeclarations($context);
                TypeErrorRaise::ensureLinked($context);
                TypeErrorRaise::emitArgumentCountError(
                    $context,
                    'parse_str() expects exactly 2 arguments, 1 given'
                );

                return $context->getTypeFromString('int32')->constInt(0, false);
            }
            JitParseStr::parseIntoScope($context, $args[0]);

            return $context->getTypeFromString('int32')->constInt(0, false);
        }

        JitParseStr::parse($context, $args[0], $args[1]);

        return $context->getTypeFromString('int64')->constInt(1, false);
    }
}
