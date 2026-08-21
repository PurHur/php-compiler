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
 * openssl_pkey_get_private() — load private key (php-src ext/openssl/openssl.c; #6295 VM, JIT/AOT #33510).
 *
 * JIT/AOT leftover #33510: catchable argc/TypeError paths (peer openssl_pkey_get_public #33499).
 * Happy-path key/PEM → OpenSSLAsymmetricKey still needs key-object AOT (#6295 follow-up).
 */
final class openssl_pkey_get_private extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkey_get_private');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'openssl_pkey_get_private() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $passphrase = null;
        if (2 === $argc) {
            $passVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING === $passVar->type) {
                $passphrase = $passVar->toString();
            } elseif (Variable::TYPE_NULL !== $passVar->type) {
                throw new \TypeError(\sprintf(
                    'openssl_pkey_get_private(): Argument #2 ($passphrase) must be of type ?string, %s given',
                    match ($passVar->type) {
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_INTEGER => 'int',
                        Variable::TYPE_FLOAT => 'float',
                        Variable::TYPE_ARRAY => 'array',
                        Variable::TYPE_OBJECT => $passVar->toObject()->class->name,
                        default => 'mixed',
                    }
                ));
            }
        }

        $key = VmOpenssl::pkeyGetPrivate($frame->calledArgs[0], $passphrase, $frame->vmContext, $frame);
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
                'openssl_pkey_get_private() expects 1 or 2 arguments, '.$argc.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_pkey_get_private_argc_cont');

            return self::jitReturnFalse($context);
        }

        $badKey = self::compileTimeNonPrivateKeyLabel($args[0]);
        if (null !== $badKey) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'openssl_pkey_get_private(): Argument #1 ($private_key) must be of type '
                .'OpenSSLAsymmetricKey|string, '.$badKey.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_pkey_get_private_te_key_cont');

            return self::jitReturnFalse($context);
        }

        if (2 === $argc) {
            $badPass = self::compileTimeNonPassphraseLabel($args[1]);
            if (null !== $badPass) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'openssl_pkey_get_private(): Argument #2 ($passphrase) must be of type ?string, '.$badPass.' given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_pkey_get_private_te_pass_cont');

                return self::jitReturnFalse($context);
            }
        }

        // Key/PEM objects stay VM-shaped (#7268 / #6295). Clear LogicException on
        // TypeError/argc gates first (#33510); happy-path bake is a follow-up.
        throw new \LogicException(
            'openssl_pkey_get_private() is not implemented for JIT in this compiler build (issue #6295/#33510)'
        );
    }

    /**
     * VM union via VmOpenssl::pkeyGetPrivate: OpenSSLAsymmetricKey|string.
     *
     * @return non-empty-string|null
     */
    private static function compileTimeNonPrivateKeyLabel(JITVariable $arg): ?string
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
            default => 'mixed',
        };
    }

    private static function objectTypeErrorLabel(JITVariable $arg): ?string
    {
        $class = $arg->classUserType;
        if (null === $class || '' === $class) {
            // Runtime / opaque object slot — accept; may be asymmetric key.
            return null;
        }
        if (0 === \strcasecmp($class, 'OpenSSLAsymmetricKey')) {
            return null;
        }

        return $class;
    }

    /**
     * Optional ?string passphrase.
     *
     * @return non-empty-string|null
     */
    private static function compileTimeNonPassphraseLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return null;
        }

        return match ($arg->type) {
            JITVariable::TYPE_STRING => null,
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_HASHTABLE => 'array',
            JITVariable::TYPE_OBJECT => ($arg->classUserType !== null && $arg->classUserType !== '')
                ? $arg->classUserType
                : 'object',
            default => 'mixed',
        };
    }

    private static function jitReturnFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
