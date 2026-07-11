<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mb_detect_order() — encoding detection order (php-src ext/mbstring/mbstring.c; #13100). */
final class mb_detect_order extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_detect_order');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_detect_order() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            $frame->returnVar->array(
                MbstringState::hashTableFromStringList(MbstringState::detectOrder())
            );

            return;
        }
        $result = MbstringState::detectOrder($frame->calledArgs[0]);
        $frame->returnVar->bool($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_detect_order() JIT is not supported in this compiler build'
        );
    }
}
