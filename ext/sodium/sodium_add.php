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

/**
 * sodium_add() — little-endian add into &$string1 (php-src ext/sodium/libsodium.c; #20081).
 *
 * Argument #1 is by-reference ({@see \PHPCompiler\BuiltinByRefParams}).
 * JIT/AOT by-ref string mutation matches sodium_memzero() (VM-first this build).
 */
final class sodium_add extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_add');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        $string2 = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'string2');
        VmSodium::add($frame->calledArgs[0], $string2);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
            $ret->null();
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
