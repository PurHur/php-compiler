<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mhash_keygen_s2k() — OpenPGP Salted S2K key derivation (php-src ext/hash/hash.c; #14975). */
final class mhash_keygen_s2k extends Internal
{
    public function __construct()
    {
        parent::__construct('mhash_keygen_s2k');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (4 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('mhash_keygen_s2k() expects exactly 4 arguments, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $algorithm = VmMhash::coerceAlgorithmArg($frame->calledArgs[0], 'mhash_keygen_s2k', 0, 'algo');
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'mhash_keygen_s2k', 1, 'password');
        $salt = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'mhash_keygen_s2k', 2, 'salt');
        $bytes = VmMhash::coerceByteLengthArg($frame->calledArgs[3], 'mhash_keygen_s2k', 3, 'bytes');
        $result = VmMhash::keygenS2k($algorithm, $password, $salt, $bytes);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT in this compiler build (issue #14975)');
    }
}
