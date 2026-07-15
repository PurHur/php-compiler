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
 * openssl_pkcs12_export_to_file() — write PKCS#12 keystore (php-src ext/openssl/pkcs12.c; #6420).
 */
final class openssl_pkcs12_export_to_file extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkcs12_export_to_file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(
                'openssl_pkcs12_export_to_file() expects 4 or 5 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $filename = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'openssl_pkcs12_export_to_file',
            1,
            'output_filename'
        );
        $passphrase = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[3],
            'openssl_pkcs12_export_to_file',
            3,
            'passphrase'
        );
        $options = 5 === $argc ? $frame->calledArgs[4] : null;
        $blob = VmOpenssl::pkcs12Export(
            $frame->calledArgs[0],
            $frame->calledArgs[2],
            $passphrase,
            $options,
            $frame
        );
        if (false === $blob) {
            $frame->returnVar->bool(false);

            return;
        }

        if (false === @\file_put_contents($filename, $blob)) {
            VmOpenssl::userWarningForFrame(
                'openssl_pkcs12_export_to_file(): Cannot open file',
                $frame
            );
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_pkcs12_export_to_file() is not implemented for JIT in this compiler build (issue #6420)'
        );
    }
}
