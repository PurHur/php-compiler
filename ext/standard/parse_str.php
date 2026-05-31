<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * parse_str() — query string parser (VM via PHP; JIT/AOT via __compiler_parse_str).
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
        $encoded = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $encoded->type) {
            throw new \LogicException('parse_str() argument #1 must be a string in this compiler build');
        }
        if (1 === $argc) {
            $caller = VmScope::requireCaller($frame);
            $params = [];
            \parse_str($encoded->toString(), $params);
            VmParseStr::importIntoCaller($caller, $params);

            return;
        }

        $resultArg = $frame->calledArgs[1];
        $target = $resultArg->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $target->type) {
            throw new \LogicException('parse_str() argument #2 must be an array in this compiler build');
        }

        $params = [];
        \parse_str($encoded->toString(), $params);
        $parsed = new \PHPCompiler\VM\HashTable();
        VmParseStr::mergeInto($parsed, $params);
        $replacement = new Variable(Variable::TYPE_ARRAY);
        $replacement->array($parsed);
        $target->copyFrom($replacement);

        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('parse_str() requires one or two arguments in this compiler build');
        }
        if (1 === \count($args)) {
            JitParseStr::parseIntoScope($context, $args[0]);

            return $context->getTypeFromString('int32')->constInt(0, false);
        }

        JitParseStr::parse($context, $args[0], $args[1]);

        return $context->getTypeFromString('int64')->constInt(1, false);
    }
}
