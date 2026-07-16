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
 * openssl_cms_verify() — CMS/S/MIME verify (php-src ext/openssl/openssl.c; #6592).
 */
final class openssl_cms_verify extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_cms_verify');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 9) {
            throw new \ArgumentCountError(
                'openssl_cms_verify() expects at least 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_cms_verify', 0, 'input_filename');
        $flags = 0;
        if ($argc >= 2) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'openssl_cms_verify', 2, 'flags');
        }

        $signersFile = null;
        if ($argc >= 3) {
            $signersFile = self::optionalPathArg($frame->calledArgs[2], 'openssl_cms_verify', 2, 'certificates');
        }
        $contentOut = null;
        if ($argc >= 6) {
            $contentOut = self::optionalPathArg($frame->calledArgs[5], 'openssl_cms_verify', 5, 'content');
        }
        $encoding = OpensslConstants::OPENSSL_ENCODING_SMIME;
        if ($argc >= 9) {
            $encoding = VmMath::parseIntBuiltinArgForFrame($frame, 8, 'openssl_cms_verify', 9, 'encoding');
        }

        $ok = VmOpenssl::cmsVerify($input, $flags, $signersFile, $contentOut, $encoding, $frame);
        $frame->returnVar->bool($ok);
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
            'openssl_cms_verify() is not implemented for JIT in this compiler build (issue #6592)'
        );
    }
}
