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
 * JIT/AOT: argc/TypeError paths (#33496); happy-path NestedJIT from OpenSSLAsymmetricKey (#34030).
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
        // Reject only compile-time known-wrong types. Value-box / mixed results from
        // openssl_pkey_new() stay TYPE_UNKNOWN until runtime (#34030 leftover of #33496).
        $bad = self::compileTimeNonKeyLabel($arg);
        if (null !== $bad) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf(
                    'openssl_pkey_get_details(): Argument #1 ($key) must be of type OpenSSLAsymmetricKey, %s given',
                    $bad
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'openssl_pkey_get_details_te_cont');

            return self::jitReturnFalse($context);
        }

        return JitOpensslPkeyGetDetails::details($context, $arg);
    }

    /**
     * @return non-empty-string|null type label when definitely not OpenSSLAsymmetricKey
     */
    private static function compileTimeNonKeyLabel(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            $class = $arg->classUserType;
            if (null === $class || '' === $class) {
                return null;
            }
            if (0 === \strcasecmp($class, 'OpenSSLAsymmetricKey')) {
                return null;
            }

            return $class;
        }
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return 'null';
        }

        return match ($arg->type) {
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_HASHTABLE => 'array',
            // Unknown / value-box may still be OpenSSLAsymmetricKey at runtime (#34030).
            default => null,
        };
    }

    private static function jitReturnFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }

}
