<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * sodium_memzero() — wipe string then set to null (php-src ext/sodium/libsodium.c; #3438).
 *
 * Argument #1 is by-reference ({@see \PHPCompiler\BuiltinByRefParams}).
 */
final class sodium_memzero extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_memzero');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 1);
        VmSodium::memzero($frame->calledArgs[0]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
            $ret->null();
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
