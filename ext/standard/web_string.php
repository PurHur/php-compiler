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
 * web_string() — coerce a query/body array value to trimmed string (issue #157).
 */
final class web_string extends Internal
{
    public function __construct()
    {
        parent::__construct('web_string');
    }

    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('web_string() requires two to four arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $source = $frame->calledArgs[0]->resolveIndirect();
        $keyVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $keyVar->type) {
            throw new \LogicException('web_string() key must be a string in this compiler build');
        }
        $default = '';
        if ($argc >= 3) {
            $defaultVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $defaultVar->type) {
                throw new \LogicException('web_string() default must be a string in this compiler build');
            }
            $default = $defaultVar->toString();
        }
        $maxLen = null;
        if ($argc >= 4) {
            $maxVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $maxVar->type) {
                throw new \LogicException('web_string() maxLen must be an integer in this compiler build');
            }
            $maxLen = $maxVar->toInt();
        }
        $frame->returnVar->string(
            Params::coerceString($source, $keyVar->toString(), $default, $maxLen)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitWebParams::webString($context, ...$args);
    }
}
