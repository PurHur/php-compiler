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
 * openssl_csr_new() — create certificate signing request (php-src ext/openssl/xp.c; #6421).
 *
 * JIT/AOT leftover #33527: catchable argc/TypeError paths (peer openssl_csr_sign #33517).
 * Happy-path DN/key → CSR still needs object AOT (#6421 follow-up).
 */
final class openssl_csr_new extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_csr_new');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'openssl_csr_new() expects 2 to 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $options = $argc >= 3 ? $frame->calledArgs[2] : null;
        $csr = VmOpenssl::csrNew(
            $frame->calledArgs[0],
            $frame->calledArgs[1],
            $options,
            $frame->vmContext,
            $frame
        );
        if (false === $csr) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($csr->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                'openssl_csr_new() expects 2 to 4 arguments, '.$argc.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_csr_new_argc_cont');

            return self::jitReturnFalse($context);
        }

        $badDn = self::compileTimeNonArrayLabel($args[0]);
        if (null !== $badDn) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'openssl_csr_new(): Argument #1 ($distinguished_names) must be of type array, '
                .$badDn.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_csr_new_te_cont');

            return self::jitReturnFalse($context);
        }

        if ($argc >= 3) {
            $badOptions = self::compileTimeNonNullableArrayLabel($args[2]);
            if (null !== $badOptions) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'openssl_csr_new(): Argument #3 ($options) must be of type ?array, '
                    .$badOptions.' given'
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_csr_new_opt_te_cont');

                return self::jitReturnFalse($context);
            }
        }

        // CSR/key objects stay VM-shaped (#6421). Clear LogicException on TypeError/argc
        // gates first (#33527); happy-path bake is a follow-up.
        throw new \LogicException(
            'openssl_csr_new() is not implemented for JIT in this compiler build (issue #6421/#33527)'
        );
    }

    /**
     * Zend stub: distinguished_names: array (not nullable).
     *
     * @return non-empty-string|null
     */
    private static function compileTimeNonArrayLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return null;
        }
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return 'null';
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

    /**
     * Zend stub: options: ?array.
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
