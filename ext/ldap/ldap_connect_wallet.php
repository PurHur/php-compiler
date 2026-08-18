<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\LdapRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * ldap_connect_wallet() — Oracle wallet TLS connect (php-src HAVE_ORALDAP; #20638 / #31984).
 */
final class ldap_connect_wallet extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_connect_wallet');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_connect_wallet() expects between 3 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $uri = null;
        if (Variable::TYPE_NULL !== $frame->calledArgs[0]->resolveIndirect()->type) {
            $uri = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'ldap_connect_wallet', 0, 'uri');
        }
        $wallet = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_connect_wallet', 1, 'wallet');
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ldap_connect_wallet', 2, 'password');
        $authMode = LdapConstants::GSLC_SSL_NO_AUTH;
        if (4 === $argc) {
            $modeVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $modeVar->type) {
                throw new \TypeError('ldap_connect_wallet(): Argument #4 ($auth_mode) must be of type int');
            }
            $authMode = $modeVar->toInt();
        }
        $frame->returnVar->copyFrom(LdapDnJitHelper::connect(
            $uri,
            $wallet,
            $password,
            $authMode,
            $frame->vmContext
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapConnectWallet::invoke($context, $args);
    }
}

/** LLVM lowering for ldap_connect_wallet() via LdapDnJitHelper (#31984). */
final class JitLdapConnectWallet
{
    /** @param list<JITVariable> $args */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_connect_wallet() expects between 3 and 4 arguments, %d given',
                $argc
            ));
        }

        $uri = JitStringBuiltinArg::lowerNullableString(
            $context,
            $args[0],
            'ldap_connect_wallet',
            0,
            'uri'
        );
        $wallet = JitStringBuiltinArg::lower($context, $args[1], 'ldap_connect_wallet', 1, 'wallet');
        $password = JitStringBuiltinArg::lower($context, $args[2], 'ldap_connect_wallet', 2, 'password');
        if (4 === $argc) {
            $authMode = JitIntdiv::lowerIntBuiltinArg(
                $context,
                $args[3],
                'ldap_connect_wallet',
                3,
                'auth_mode'
            );
        } else {
            $authMode = $context->getTypeFromString('int64')->constInt(
                LdapConstants::GSLC_SSL_NO_AUTH,
                false
            );
        }

        LdapRuntime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_ldap_connect_wallet'),
            $uri,
            $wallet,
            $password,
            $authMode
        );
    }
}
