<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** chgrp() — VM via VmFs; JIT/AOT via __compiler_chgrp (php-src ext/standard/filestat.c). */
final class chgrp_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chgrp');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('chgrp() requires exactly two arguments in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        $groupVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('chgrp() filename must be a string in this compiler build');
        }
        if (!\in_array($groupVar->type, [Variable::TYPE_INTEGER, Variable::TYPE_STRING], true)) {
            throw new \LogicException('chgrp() group must be int or string in this compiler build');
        }
        $frame->returnVar->bool(VmFs::chgrp($pathVar->toString(), $groupVar));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('chgrp() requires exactly two arguments in this compiler build');
        }
        $path = $this->jitString($context, $args[0], 'chgrp() argument #1');
        $groupPtr = JitValueBox::valuePtrFromVariable($context, $args[1]);

        return JitChgrp::invoke($context, $path, $groupPtr, false);
    }
}
