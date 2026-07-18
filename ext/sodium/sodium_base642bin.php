<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** sodium_base642bin() — base64 variant to binary (php-src ext/sodium/libsodium.c; #20675). */
final class sodium_base642bin extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_base642bin');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at least 2 arguments, %d given',
                $this->getName(),
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at most 3 arguments, %d given',
                $this->getName(),
                $argc
            ));
        }
        // Z_PARAM_STR $string / $ignore — null TypeError on 8.4 forward profile (peer #20196).
        $string = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'sodium_base642bin', 0, 'string');
        $id = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'sodium_base642bin', 2, 'id');
        $ignore = '';
        if ($argc >= 3) {
            $ignore = VmString::zparamStrBuiltinArgForFrame($frame, 2, 'sodium_base642bin', 2, 'ignore');
        }
        $result = VmSodium::base642bin($string, $id, $ignore);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
