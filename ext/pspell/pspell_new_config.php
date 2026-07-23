<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** pspell_new_config() — php-src ext/pspell/pspell.c (#22229). */
final class pspell_new_config extends Internal
{
    public function __construct()
    {
        parent::__construct('pspell_new_config');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pspell_new_config() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $config = VmPspellArg::requireConfig($frame->calledArgs[0], 'pspell_new_config', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pspell_new_config() requires a VM context');
        }
        $result = VmPspellCore::newDictionaryFromConfig($ctx, $frame, $config);
        if (false === $result) {
            $frame->returnVar->bool(false);
            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pspell_new_config() is not implemented for JIT in this compiler build (issue #22229)');
    }
}
