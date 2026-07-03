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

/** sodium_crypto_secretstream_xchacha20poly1305_init_pull() — pull-side init (php-src ext/sodium/libsodium.c; #15462). */
final class sodium_crypto_secretstream_xchacha20poly1305_init_pull extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_secretstream_xchacha20poly1305_init_pull');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        $header = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'header');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'key');
        $state = VmSodium::secretstreamInitPull($header, $key);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($state): void {
            $ret->string($state);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
