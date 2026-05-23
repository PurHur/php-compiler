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

/** get_class() — class name of an object (issue #1217). */
final class get_class_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_class');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('get_class() requires exactly one argument in this compiler build');
        }
        $value = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_OBJECT !== $value->type) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($value->toObject()->class->name);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_class() requires exactly one argument in this compiler build');
        }

        return ReflectionBuiltinHelper::getClassName($context, $args[0]);
    }
}
