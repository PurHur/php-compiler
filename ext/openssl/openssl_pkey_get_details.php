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
 * openssl_pkey_get_details() — key parameter array (php-src ext/openssl/openssl.c; #20240 VM, JIT/AOT #33496/#34030).
 *
 * JIT/AOT: argc/TypeError (#33496); happy-path OpenSSLAsymmetricKey → array (#34030).
 * Thin AOT: libcrypto leaf via {@see JitOpensslPkeyGetDetails} / {@see JitOpensslPkeyKernel}.
 */
final class openssl_pkey_get_details extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkey_get_details');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'openssl_pkey_get_details() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $details = VmOpenssl::pkeyGetDetails($frame->calledArgs[0], $frame);
        if (false === $details) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->copyFrom($details);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'openssl_pkey_get_details', 1)) {
            return self::jitReturnFalse($context);
        }

        $arg = $args[0];
        $badKey = self::compileTimeNonAsymmetricKeyLabel($arg);
        if (null !== $badKey) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf(
                    'openssl_pkey_get_details(): Argument #1 ($key) must be of type OpenSSLAsymmetricKey, %s given',
                    $badKey
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_pkey_get_details_te_cont');

            return self::jitReturnFalse($context);
        }

        return JitOpensslPkeyGetDetails::invoke($context, $arg);
    }

    /**
     * Zend stub: OpenSSLAsymmetricKey only.
     * Compile-time null/bool/int/float/string/array (and wrong named objects) → TypeError.
     * Opaque / value-box results from openssl_pkey_new() fall through (#34030).
     *
     * @return non-empty-string|null
     */
    private static function compileTimeNonAsymmetricKeyLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return 'null';
        }

        return match ($arg->type) {
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_HASHTABLE => 'array',
            JITVariable::TYPE_OBJECT => self::objectTypeErrorLabel($arg),
            // Value-box / unknown from openssl_pkey_new() — accept for happy path.
            default => null,
        };
    }

    private static function objectTypeErrorLabel(JITVariable $arg): ?string
    {
        $class = $arg->classUserType;
        if (null === $class || '' === $class) {
            return null;
        }
        if (0 === \strcasecmp($class, 'OpenSSLAsymmetricKey')) {
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
