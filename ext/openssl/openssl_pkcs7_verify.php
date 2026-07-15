<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_pkcs7_verify() — S/MIME verify (php-src ext/openssl/openssl.c; #6804).
 */
final class openssl_pkcs7_verify extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkcs7_verify');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 7) {
            throw new \ArgumentCountError(
                'openssl_pkcs7_verify() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_pkcs7_verify', 0, 'input_filename');
        $flags = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'openssl_pkcs7_verify', 2, 'flags');

        $signersFile = null;
        if ($argc >= 3) {
            $signersFile = self::optionalPathArg($frame->calledArgs[2], 'openssl_pkcs7_verify', 2, 'signers_certificates_filename');
        }
        $contentOut = null;
        if ($argc >= 6) {
            $contentOut = self::optionalPathArg($frame->calledArgs[5], 'openssl_pkcs7_verify', 5, 'content');
        }

        $result = VmOpenssl::pkcs7Verify($input, $flags, $signersFile, $contentOut, $frame);
        if (\is_int($result)) {
            $frame->returnVar->int($result);
        } else {
            $frame->returnVar->bool($result);
        }
    }

    private static function optionalPathArg(Variable $var, string $function, int $argIndex, string $paramName): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $function, $argIndex, $paramName);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_pkcs7_verify() is not implemented for JIT in this compiler build (issue #6804)'
        );
    }
}
