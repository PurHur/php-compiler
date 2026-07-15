<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrtok;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
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
        $str = VmString::coerceStrtokStringArg($frame->calledArgs[0]);
        $tok = null;
        if (2 === $argc) {
            $tok = VmString::coerceStrtokTokenArg($frame->calledArgs[1]);
        }
        $result = VmString::strtok($str, $tok);
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
                JitStringBuiltinArg::lower($context, $args[0], 'strtok', 0, 'token', 'string', 'string')
            );
        }

        $tok = JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'strtok',
            1,
            'token',
            '?string',
            '?string'
        );
        return JitStrtok::tokenize(
            $context,
            JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'strtok', 0, 'string', 'string', 'string'),
            $tok
        );
    }
}
