<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** pspell_config_personal() — php-src ext/pspell/pspell.c (#22229). */
final class pspell_config_personal extends Internal
{
    public function __construct()
    {
        parent::__construct('pspell_config_personal');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_config_personal() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $config = VmPspellArg::requireConfig($frame->calledArgs[0], 'pspell_config_personal', 1);
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'pspell_config_personal', 2, 'filename');
        $ok = VmPspellCore::configReplace($config, 'personal', $path);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pspell_config_personal() is not implemented for JIT in this compiler build (issue #22229)');
    }
}
