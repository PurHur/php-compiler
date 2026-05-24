<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * get_parent_class() — false until class extends is implemented (issue #1218).
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
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type) {
            $frame->returnVar->bool(false);

            return;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            VmReflection::stringArg($arg, 'get_parent_class() class name');
            $frame->returnVar->bool(false);

            return;
        }
        throw new \LogicException('get_parent_class() argument must be an object or class name string');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('get_parent_class() requires one or two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'get_parent_class() class name');
        }
        if (JITVariable::TYPE_OBJECT === $args[0]->type) {
            return ReflectionBuiltinHelper::getParentClassLiteral($context);
        }
        ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[0],
            'get_parent_class() class name'
        );

        return ReflectionBuiltinHelper::getParentClassLiteral($context);
    }
}
