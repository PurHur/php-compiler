<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** sodium_hex2bin() — hex to binary (php-src ext/sodium/libsodium.c; #3438). */
final class sodium_hex2bin extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_hex2bin');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at least 1 argument, %d given',
                $this->getName(),
                $argc
            ));
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at most 2 arguments, %d given',
                $this->getName(),
                $argc
            ));
        }
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'string');
        $ignore = '';
        if ($argc >= 2) {
            $ignore = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'ignore');
        }
        $result = VmSodium::hex2bin($string, $ignore);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
