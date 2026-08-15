<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ctype;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for ctype builtins (php-src ext/ctype/ctype.c; #7253).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30602).
 */
abstract class CtypeFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/ctype/ctype.c — ZEND_PARSE_PARAMETERS → ArgumentCountError (#30602).
        $this->requireExactArgCount($frame, $this->getName(), 1);
        $spec = VmCtype::specForFunction($this->getName());
        $result = VmCtype::evaluate(
            $frame->calledArgs[0],
            $this->getName(),
            $spec['kind'],
            $spec['allow_digits'],
            $spec['allow_minus'],
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // php-src ext/ctype/ctype.c — ArgumentCountError (#30602).
        if (!$this->requireExactJitArgCount($context, $args, $this->getName(), 1)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        return JitCtype::invoke($context, $args[0], $this->getName());
    }
}
