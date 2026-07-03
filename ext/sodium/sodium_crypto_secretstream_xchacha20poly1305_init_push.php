<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** sodium_crypto_secretstream_xchacha20poly1305_init_push() — push-side init (php-src ext/sodium/libsodium.c; #15462). */
final class sodium_crypto_secretstream_xchacha20poly1305_init_push extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_secretstream_xchacha20poly1305_init_push');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 1);
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'key');
        [$state, $header] = VmSodium::secretstreamInitPush($key);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($state, $header): void {
            $ret->copyFrom(VmJson::import([$state, $header]));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
