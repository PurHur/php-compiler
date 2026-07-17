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

/**
 * Shared VM wiring for sodium_crypto_kx_*_session_keys() (#20047).
 *
 * php-src: ext/sodium/libsodium.c
 */
abstract class SodiumKxSessionKeysFunction extends Internal
{
    abstract protected function keypairParamName(): string;

    abstract protected function peerParamName(): string;

    /** @return array{0: string, 1: string} */
    abstract protected function invoke(string $keypair, string $peerPublicKey): array;

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        $keypair = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            $this->getName(),
            0,
            $this->keypairParamName()
        );
        $peer = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            $this->getName(),
            1,
            $this->peerParamName()
        );
        $result = $this->invoke($keypair, $peer);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->copyFrom(VmJson::import($result));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
