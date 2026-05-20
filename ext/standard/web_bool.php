<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Params;
use PHPLLVM\Value;

/**
 * web_bool() — coerce a query/body array value to bool (issue #157).
 */
final class web_bool extends Internal
{
    public function __construct()
    {
        parent::__construct('web_bool');
    }

    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('web_bool() requires two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $source = $frame->calledArgs[0]->resolveIndirect();
        $keyVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $keyVar->type) {
            throw new \LogicException('web_bool() key must be a string in this compiler build');
        }
        $default = false;
        if (3 === $argc) {
            $defaultVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $defaultVar->type) {
                throw new \LogicException('web_bool() default must be a boolean in this compiler build');
            }
            $default = $defaultVar->toBool();
        }
        $frame->returnVar->bool(
            Params::coerceBool($source, $keyVar->toString(), $default)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'web_bool() is not implemented for JIT in this compiler build; use web_int($source, $key, 0) for AOT or VM'
        );
    }
}
