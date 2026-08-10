<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_digest() — one-shot digest (#6228 VM, #21081 JIT/AOT NestedJIT; ext/openssl/openssl.c).
 */
final class openssl_digest extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_digest');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'openssl_digest() expects 2 or 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR $data — soft-null DEP+coerce on forward profile (#21517, reverts #20207;
        // php-src ext/openssl/openssl.c — Zend 8.4 still deprecates null → '').
        $data = VmString::trimFamilyStringArgForFrame($frame, 0, 'openssl_digest', 0, 'data');
        // Z_PARAM_STR $digest_algo — TypeError under caller strict_types (#29956; openssl.stub.php).
        $method = VmString::stringBuiltinArgForFrame($frame, 1, 'openssl_digest', 1, 'digest_algo');
        $rawOutput = false;
        if (3 === $argc) {
            $rawOutput = VmOpenssl::coerceBoolArg(
                $frame->calledArgs[2],
                'openssl_digest',
                2,
                'raw_output'
            );
        }
        $digest = VmOpenssl::digest($data, $method, $rawOutput, $frame);
        if (false === $digest) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($digest);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'openssl_digest() expects 2 or 3 arguments, '.$argc.' given'
            );
        }

        return JitOpensslDigest::digest(
            $context,
            $args[0],
            $args[1],
            $args[2] ?? null
        );
    }
}
