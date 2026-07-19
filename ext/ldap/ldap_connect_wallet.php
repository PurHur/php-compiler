<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * ldap_connect_wallet() — Oracle wallet TLS connect (php-src HAVE_ORALDAP; #20638).
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
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ldap_connect_wallet() requires a VM context');
        }
        if (!VmLdapNative::walletAvailable()) {
            @\trigger_error('ldap_connect_wallet(): Oracle wallet LDAP support is not available in this build', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $url = $uri ?? 'ldap://localhost';
        if (!str_contains($url, '://')) {
            $url = 'ldap://'.$url;
        }
        $native = VmLdapNative::initializeWallet($url, $wallet, $password, $authMode);
        if (null === $native) {
            @\trigger_error('ldap_connect_wallet(): Could not create session handle', \E_USER_WARNING);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmLdapConnection::wrap($native, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_connect_wallet() is not implemented for JIT in this compiler build (issue #20638)');
    }
}
