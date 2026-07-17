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
 * openssl_x509_export_to_file() — write certificate PEM to path (php-src ext/openssl/openssl.c; #20273).
 */
final class openssl_x509_export_to_file extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_x509_export_to_file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'openssl_x509_export_to_file() expects 2 or 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $filename = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'openssl_x509_export_to_file',
            1,
            'output_filename'
        );

        $noText = true;
        if (3 === $argc) {
            $noText = VmOpenssl::coerceBoolArg(
                $frame->calledArgs[2],
                'openssl_x509_export_to_file',
                2,
                'no_text'
            );
        }

        $exported = VmOpenssl::x509ExportPem(
            $frame->calledArgs[0],
            $noText,
            $frame,
            'openssl_x509_export_to_file'
        );
        if (false === $exported) {
            $frame->returnVar->bool(false);

            return;
        }

        if (false === @\file_put_contents($filename, $exported)) {
            VmOpenssl::userWarningForFrame(
                'openssl_x509_export_to_file(): Error opening file '.$filename,
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
            'openssl_x509_export_to_file() is not implemented for JIT in this compiler build (issue #20273)'
        );
    }
}
