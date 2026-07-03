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

/** sodium_crypto_sign_publickey_from_secretkey() — derive public key (php-src ext/sodium/libsodium.c; #15541). */
final class sodium_crypto_sign_publickey_from_secretkey extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_sign_publickey_from_secretkey');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 1);
        $secretkey = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'secret_key');
        $result = VmSodium::signPublickeyFromSecretkey($secretkey);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
