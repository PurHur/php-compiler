<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mb_substitute_character() — illegal-byte replacement (php-src ext/mbstring/mbstring.c; #13100). */
final class mb_substitute_character extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_substitute_character');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_substitute_character() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            $value = MbstringState::substituteCharacter();
            if (\is_int($value)) {
                $frame->returnVar->int($value);
            } else {
                $frame->returnVar->string($value);
            }

            return;
        }
        $result = MbstringState::substituteCharacter($frame->calledArgs[0]);
        $frame->returnVar->bool($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_substitute_character() JIT is not supported in this compiler build'
        );
    }
}
