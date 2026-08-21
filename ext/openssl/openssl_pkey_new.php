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
 * openssl_pkey_new() — generate asymmetric key (php-src ext/openssl/openssl.c; #6295, #22335).
 *
 * Reflection / named-arg param is Zend stub `options` (not InternalArgInfo `configargs`; #24491).
 *
 * JIT/AOT leftover #33530: catchable argc/TypeError paths (peer openssl_csr_new #33527).
 * Happy-path key generation still needs object AOT (#6295 follow-up).
 */
final class openssl_pkey_new extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkey_new');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'openssl_pkey_new() expects at most 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $config = 1 === \count($frame->calledArgs) ? $frame->calledArgs[0] : null;
        $key = VmOpenssl::pkeyNew($config, $frame->vmContext, $frame);
        if (false === $key) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($key->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'openssl_pkey_new() expects at most 1 argument, '.$argc.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_pkey_new_argc_cont');

            return self::jitReturnFalse($context);
        }

        if (1 === $argc) {
            $badOptions = self::compileTimeNonNullableArrayLabel($args[0]);
            if (null !== $badOptions) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'openssl_pkey_new(): Argument #1 ($options) must be of type ?array, '
                    .$badOptions.' given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_pkey_new_te_cont');

                return self::jitReturnFalse($context);
            }
        }

        // Key objects stay VM-shaped (#6295). Clear LogicException on TypeError/argc
        // gates first (#33530); happy-path bake is a follow-up.
        throw new \LogicException(
            'openssl_pkey_new() is not implemented for JIT in this compiler build (issue #6295/#22335/#33530)'
        );
    }

    /**
     * Zend stub: options: ?array — null is allowed; other non-array types TypeError.
     *
     * @return non-empty-string|null
     */
    private static function compileTimeNonNullableArrayLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return null;
        }
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return null;
        }

        return match ($arg->type) {
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_OBJECT => (null !== $arg->classUserType && '' !== $arg->classUserType)
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
