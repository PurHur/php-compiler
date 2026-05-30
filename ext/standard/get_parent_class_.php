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
 * get_parent_class() — parent class from extends chain (issue #3483).
 *
 * php-src: ext/standard/class.c — PHP_FUNCTION(get_parent_class)
 */
final class get_parent_class_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_parent_class');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1 || \count($frame->calledArgs) > 2) {
            throw new \LogicException('get_parent_class() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $arg = $frame->calledArgs[0]->resolveIndirect();
        $entry = null;
        if (Variable::TYPE_OBJECT === $arg->type) {
            $entry = $arg->toObject()->class;
        } elseif (Variable::TYPE_STRING === $arg->type) {
            VmReflection::stringArg($arg, 'get_parent_class() class name');
            $entry = VmReflection::resolveClassEntry($ctx, $arg->toString());
        } else {
            throw new \LogicException('get_parent_class() argument must be an object or class name string');
        }
        if (null === $entry || $entry->isInterface || $entry->isTrait || $entry->isEnum) {
            $frame->returnVar->bool(false);

            return;
        }
        $parentName = VmReflection::parentClassName($entry, $ctx);
        if (null === $parentName) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($parentName);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('get_parent_class() requires one or two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'get_parent_class() class name');
        }

        return JitGetParentClass::invoke($context, $args[0]);
    }
}
