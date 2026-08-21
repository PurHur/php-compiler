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
 * openssl_pkey_get_details() — key parameter array (php-src ext/openssl/openssl.c; #20240 VM, JIT/AOT #33496).
 *
 * JIT/AOT leftover #33496: catchable argc/TypeError paths (peer openssl_pkey_free #33487).
 * Happy-path OpenSSLAsymmetricKey → array still needs key-object / PEM AOT (#6295 follow-up).
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
        if (!self::jitArgIsAsymmetricKey($arg)) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf(
                    'openssl_pkey_get_details(): Argument #1 ($key) must be of type OpenSSLAsymmetricKey, %s given',
                    self::jitTypeLabel($arg)
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_pkey_get_details_te_cont');

            return self::jitReturnFalse($context);
        }

        // Key objects stay VM-shaped for details arrays (#7268 / #6295). Clear LogicException on
        // TypeError/argc gates first (#33496); happy-path bake is a follow-up.
        throw new \LogicException(
            'openssl_pkey_get_details() is not implemented for JIT in this compiler build (issue #20240/#33496)'
        );
    }

    private static function jitArgIsAsymmetricKey(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_OBJECT !== $arg->type) {
            return false;
        }
        $class = $arg->classUserType;
        if (null === $class || '' === $class) {
            return true;
        }

        return 0 === \strcasecmp($class, 'OpenSSLAsymmetricKey');
    }

    private static function jitReturnFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }

    private static function jitTypeLabel(JITVariable $arg): string
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
            JITVariable::TYPE_OBJECT => (null !== $arg->classUserType && '' !== $arg->classUserType)
                ? $arg->classUserType
                : 'object',
            default => 'mixed',
        };
    }
}
