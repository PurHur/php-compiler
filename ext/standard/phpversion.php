<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** phpversion() — runtime version string (ext/standard/info.c parity, issue #3174). */
final class phpversion extends Internal
{
    public function __construct()
    {
        parent::__construct('phpversion');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError('phpversion() expects at most 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $extension = null;
        if (1 === $argc) {
            $extension = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[0],
                'phpversion',
                0,
                'extension'
            );
        }
        $result = VmInfo::phpversion($extension);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError('phpversion() expects at most 1 argument, '.\count($args).' given');
        }

        return JitInfo::phpversion($context, $args[0] ?? null);
    }
}
