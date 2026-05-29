<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrtok;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * strtok() — tokenize strings with static continuation state (php-src ext/standard/string.c; #3201).
 */
final class strtok extends Internal
{
    public function __construct()
    {
        parent::__construct('strtok');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('strtok() accepts one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $arg0 = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $arg0->type) {
            throw new \LogicException('strtok() argument #1 must be a string in this compiler build');
        }
        $tok = null;
        if (2 === $argc) {
            $arg1 = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $arg1->type) {
                throw new \LogicException('strtok() argument #2 must be a string in this compiler build');
            }
            $tok = $arg1->toString();
        }
        $result = VmString::strtok($arg0->toString(), $tok);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('strtok() accepts one or two arguments in this compiler build');
        }
        StringStrtok::ensureLinked($context);
        if (1 === $argc) {
            return JitStrtok::tokenize(
                $context,
                null,
                $this->jitString($context, $args[0], 'strtok() token')
            );
        }

        return JitStrtok::tokenize(
            $context,
            $this->jitString($context, $args[0], 'strtok() string'),
            $this->jitString($context, $args[1], 'strtok() token')
        );
    }
}
