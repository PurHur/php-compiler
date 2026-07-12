<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mb_http_input() — HTTP input encoding probe (php-src ext/mbstring/mbstring.c; #4636). */
final class mb_http_input extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_http_input');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_http_input() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $type = 0 === $argc
            ? null
            : VmMbstring::coerceOptionalHttpInputTypeArg($frame->calledArgs[0], 'mb_http_input', 0);
        $result = MbstringState::httpInput($type);
        if (\is_array($result)) {
            $frame->returnVar->array(MbstringState::hashTableFromStringList($result));

            return;
        }
        if (\is_string($result)) {
            $frame->returnVar->string($result);

            return;
        }
        $frame->returnVar->bool(false);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'mb_http_input() JIT is not supported in this compiler build'
        );
    }
}
