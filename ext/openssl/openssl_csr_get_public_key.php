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
use PHPLLVM\Value;

/**
 * openssl_csr_get_public_key() — public key from CSR (php-src ext/openssl/xp.c; #6421 VM, JIT/AOT #33514).
 *
 * JIT/AOT: argc/TypeError (#33514); happy-path CSR PEM → OpenSSLAsymmetricKey (#34054).
 */
final class openssl_csr_get_public_key extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_csr_get_public_key');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'openssl_csr_get_public_key() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $key = VmOpenssl::csrGetPublicKey($frame->calledArgs[0], $frame->vmContext, $frame);
        if (false === $key) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($key->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'openssl_csr_get_public_key() expects 1 or 2 arguments, '.$argc.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_csr_get_public_key_argc_cont');

            return self::jitReturnFalse($context);
        }

        $badLabel = self::compileTimeNonCsrLabel($args[0]);
        if (null !== $badLabel) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'openssl_csr_get_public_key(): Argument #1 ($csr) must be of type '
                .'OpenSSLCertificateSigningRequest|string, '.$badLabel.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_csr_get_public_key_te_cont');

            return self::jitReturnFalse($context);
        }

        return JitOpensslCsrGetPublicKey::invoke($context, $args[0]);
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
            // Value-box / unknown may still be CSR PEM string at runtime (#34054 peer #34038).
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
