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

/** chown() — VM via VmFs; JIT/AOT via __compiler_chown (php-src ext/standard/filestat.c). */
final class chown_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chown');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('chown() requires exactly two arguments in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        $userVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('chown() filename must be a string in this compiler build');
        }
        if (!\in_array($userVar->type, [Variable::TYPE_INTEGER, Variable::TYPE_STRING], true)) {
            throw new \LogicException('chown() user must be int or string in this compiler build');
        }
        $frame->returnVar->bool(VmFs::chown($pathVar->toString(), $userVar));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('chown() requires exactly two arguments in this compiler build');
        }
        $path = $this->jitString($context, $args[0], 'chown() argument #1');
        $userPtr = JitValueBox::valuePtrFromVariable($context, $args[1]);

        return JitChown::invoke($context, $path, $userPtr, false);
    }
}
