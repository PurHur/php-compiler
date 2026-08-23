<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * openssl_csr_sign() — sign CSR into X.509 certificate (php-src ext/openssl/openssl.c; #6421 VM, JIT/AOT #33517).
 *
 * JIT/AOT leftover #33517: catchable argc/TypeError paths (peer openssl_csr_get_public_key #33514).
 * JIT/AOT: argc/TypeError (#33517); happy-path CSR PEM + key → OpenSSLCertificate (#34060).
 */
final class openssl_csr_sign extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_csr_sign');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 6) {
            throw new \ArgumentCountError(
                'openssl_csr_sign() expects 4 to 6 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $daysVar = $frame->calledArgs[3]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $daysVar->type) {
            throw new \TypeError(\sprintf(
                'openssl_csr_sign(): Argument #4 ($days) must be of type int, %s given',
                match ($daysVar->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_FLOAT => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_ARRAY => 'array',
                    Variable::TYPE_OBJECT => 'object',
                    default => null,
                }
            ));
        }
        $days = $daysVar->toInt();

        $options = $argc >= 5 ? $frame->calledArgs[4] : null;
        $serial = 0;
        if ($argc >= 6) {
            $serialVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $serialVar->type) {
                throw new \TypeError(\sprintf(
                    'openssl_csr_sign(): Argument #6 ($serial) must be of type int, %s given',
                    match ($serialVar->type) {
                        Variable::TYPE_NULL => 'null',
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_FLOAT => 'float',
                        Variable::TYPE_STRING => 'string',
                        Variable::TYPE_ARRAY => 'array',
                        Variable::TYPE_OBJECT => 'object',
                        default => 'mixed',
                    }
                ));
            }
            $serial = $serialVar->toInt();
        }

        $cert = VmOpenssl::csrSign(
            $frame->calledArgs[0],
            $frame->calledArgs[1],
            $frame->calledArgs[2],
            $days,
            $options,
            $serial,
            $frame->vmContext,
            $frame
        );
        if (false === $cert) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($cert->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        file_put_contents("/tmp/call_probe.txt", "enter\n", FILE_APPEND);
        $argc = \count($args);
        if ($argc < 4 || $argc > 6) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'openssl_csr_sign() expects 4 to 6 arguments, '.$argc.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_csr_sign_argc_cont');

            return self::jitReturnFalse($context);
        }

        $badCsr = self::compileTimeNonCsrLabel($args[0]);
        if (null !== $badCsr) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'openssl_csr_sign(): Argument #1 ($csr) must be of type '
                .'OpenSSLCertificateSigningRequest|string, '.$badCsr.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_csr_sign_te_cont');

            return self::jitReturnFalse($context);
        }

        // Happy-path CSR PEM + key PEM + days → OpenSSLCertificate (#34060 leftover of #33517).
        $options = $argc >= 5 ? $args[4] : null;
        $serial = $argc >= 6 ? $args[5] : null;

        return JitOpensslCsrSign::invoke(
            $context,
            $args[0],
            $args[1],
            $args[2],
            $args[3],
            $options,
            $serial
        );
    }

    /**
     * VM union via resolveCsrPem: OpenSSLCertificateSigningRequest|string.
     *
     * @return non-empty-string|null
     */
    private static function compileTimeNonCsrLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return 'null';
        }

        return match ($arg->type) {
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_HASHTABLE => 'array',
            JITVariable::TYPE_STRING => null,
            JITVariable::TYPE_OBJECT => self::objectTypeErrorLabel($arg),
            // Value-box / unknown may still be CSR PEM string at runtime (#34060 peer #34054).
            default => null,
        };
    }

    private static function objectTypeErrorLabel(JITVariable $arg): ?string
    {
        $class = $arg->classUserType;
        if (null === $class || '' === $class) {
            return null;
        }
        if (0 === \strcasecmp($class, 'OpenSSLCertificateSigningRequest')) {
            return null;
        }

        return $class;
    }

    private static function jitReturnFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
