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
 * openssl_pkey_get_public() — load public key (php-src ext/openssl/openssl.c; #20240 VM, JIT/AOT #33499).
 *
 * JIT/AOT: argc/TypeError paths (#33499); happy-path PEM/key → OpenSSLAsymmetricKey (#34038).
 */
final class openssl_pkey_get_public extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkey_get_public');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'openssl_pkey_get_public() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $key = VmOpenssl::pkeyGetPublic(
            $frame->calledArgs[0],
            $frame->vmContext,
            'openssl_pkey_get_public',
            $frame
        );
        if (false === $key) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($key->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'openssl_pkey_get_public', 1)) {
            return self::jitReturnFalse($context);
        }

        $arg = $args[0];
        $badLabel = self::compileTimeNonPublicKeyLabel($arg);
        if (null !== $badLabel) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'openssl_pkey_get_public(): Argument #1 ($public_key) must be of type '
                .'OpenSSLAsymmetricKey|OpenSSLCertificate|array|string, '.$badLabel.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_pkey_get_public_te_cont');

            return self::jitReturnFalse($context);
        }

        // Hashtable / value-box PEMs from openssl_pkey_get_details()['key'] stay TYPE_UNKNOWN
        // until runtime — accept them (peer #34030); wrong scalars already rejected above.
        return JitOpensslPkeyGetPublic::fromArg($context, $arg);
    }

    /**
     * Zend stub union: OpenSSLAsymmetricKey|OpenSSLCertificate|array|string.
     * Compile-time null/bool/int/float (and wrong named objects) → TypeError.
     * Opaque objects / string / array / value-box fall through to happy-path (#34038).
     *
     * @return non-empty-string|null
     */
    private static function compileTimeNonPublicKeyLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return 'null';
        }

        return match ($arg->type) {
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_STRING, JITVariable::TYPE_HASHTABLE => null,
            JITVariable::TYPE_OBJECT => self::objectTypeErrorLabel($arg),
            // Unknown / value-box may still be string|key at runtime (#34038).
            default => null,
        };
    }

    private static function objectTypeErrorLabel(JITVariable $arg): ?string
    {
        $class = $arg->classUserType;
        if (null === $class || '' === $class) {
            // Runtime / opaque object slot — accept; may be key or cert.
            return null;
        }
        if (0 === \strcasecmp($class, 'OpenSSLAsymmetricKey')
            || 0 === \strcasecmp($class, 'OpenSSLCertificate')) {
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
