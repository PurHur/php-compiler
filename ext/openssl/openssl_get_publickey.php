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
 * openssl_get_publickey() — alias of openssl_pkey_get_public (php-src; #20240 VM, JIT/AOT #33503).
 *
 * JIT/AOT: argc/TypeError paths (#33503); happy-path shares #34038 with openssl_pkey_get_public.
 */
final class openssl_get_publickey extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_get_publickey');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'openssl_get_publickey() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $key = VmOpenssl::pkeyGetPublic(
            $frame->calledArgs[0],
            $frame->vmContext,
            'openssl_get_publickey',
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
        if (!$this->requireExactJitArgCount($context, $args, 'openssl_get_publickey', 1)) {
            return self::jitReturnFalse($context);
        }

        $arg = $args[0];
        $badLabel = self::compileTimeNonPublicKeyLabel($arg);
        if (null !== $badLabel) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'openssl_get_publickey(): Argument #1 ($public_key) must be of type '
                .'OpenSSLAsymmetricKey|OpenSSLCertificate|array|string, '.$badLabel.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_get_publickey_te_cont');

            return self::jitReturnFalse($context);
        }

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
