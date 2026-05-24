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
 * get_object_vars() — accessible object properties as array (issue #1370).
 * VM only; JIT deferred until object property snapshot lowering lands.
 */
final class get_object_vars_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_object_vars');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('get_object_vars() requires exactly one argument');
        }
        $value = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $value->type) {
            throw new \LogicException('get_object_vars() expects an object in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmReflection::getObjectVars($value->toObject()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('get_object_vars() is not implemented for JIT in this compiler build (#1370)');
    }
}
