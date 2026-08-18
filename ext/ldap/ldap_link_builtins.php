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
 * Phase-1 ldap link/result builtins (php-src ext/ldap/ldap.c; #3369).
 */

final class ldap_bind extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_bind');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_bind() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_bind', 1);
        $dn = null;
        if ($argc >= 2 && Variable::TYPE_NULL !== $frame->calledArgs[1]->resolveIndirect()->type) {
            $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_bind', 1, 'dn');
        }
        $password = null;
        if ($argc >= 3 && Variable::TYPE_NULL !== $frame->calledArgs[2]->resolveIndirect()->type) {
            $password = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ldap_bind', 2, 'password');
        }
        $ok = VmLdapCore::bind($conn, $dn, $password);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeBind($context, $args);
    }
}

final class ldap_bind_ext extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_bind_ext');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_bind_ext() expects between 1 and 4 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_bind_ext', 1);
        $dn = null;
        if ($argc >= 2 && Variable::TYPE_NULL !== $frame->calledArgs[1]->resolveIndirect()->type) {
            $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_bind_ext', 1, 'dn');
            if (str_contains($dn, "\0")) {
                throw new \TypeError('ldap_bind_ext(): Argument #2 ($dn) must not contain null bytes');
            }
        }
        $password = null;
        if ($argc >= 3 && Variable::TYPE_NULL !== $frame->calledArgs[2]->resolveIndirect()->type) {
            $password = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ldap_bind_ext', 2, 'password');
            if (str_contains($password, "\0")) {
                throw new \TypeError('ldap_bind_ext(): Argument #3 ($password) must not contain null bytes');
            }
        }
        // arg 4 ($controls) accepted; ignored in v1 (same as ldap_exop / modify_ext).
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ldap_bind_ext() requires a VM context');
        }
        $result = VmLdapCore::bindExt($conn, $dn, $password, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->copyFrom($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeBindExt($context, $args);
    }
}

/**
 * ldap_sasl_bind() — SASL interactive bind (php-src HAVE_LDAP_SASL; #22176).
 *
 * ldap_sasl_bind(
 *   LDAP\Connection $ldap,
 *   ?string $dn = null,
 *   ?string $password = null,
 *   ?string $mech = null,
 *   ?string $realm = null,
 *   ?string $authc_id = null,
 *   ?string $authz_id = null,
 *   ?string $props = null
 * ): bool
 */
final class ldap_sasl_bind extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_sasl_bind');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 8) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_sasl_bind() expects between 1 and 8 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_sasl_bind', 1);
        $dn = self::optionalStringArg($frame, 1, 'ldap_sasl_bind', 'dn');
        $password = self::optionalStringArg($frame, 2, 'ldap_sasl_bind', 'password');
        $mech = self::optionalStringArg($frame, 3, 'ldap_sasl_bind', 'mech');
        $realm = self::optionalStringArg($frame, 4, 'ldap_sasl_bind', 'realm');
        $authcId = self::optionalStringArg($frame, 5, 'ldap_sasl_bind', 'authc_id');
        $authzId = self::optionalStringArg($frame, 6, 'ldap_sasl_bind', 'authz_id');
        $props = self::optionalStringArg($frame, 7, 'ldap_sasl_bind', 'props');
        $ok = VmLdapCore::saslBind($conn, $dn, $password, $mech, $realm, $authcId, $authzId, $props);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    private static function optionalStringArg(Frame $frame, int $idx, string $fn, string $name): ?string
    {
        if (!isset($frame->calledArgs[$idx])) {
            return null;
        }
        $var = $frame->calledArgs[$idx]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($frame->calledArgs[$idx], $fn, $idx, $name);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeSaslBind($context, $args);
    }
}

/**
 * ldap_set_rebind_proc() — referral rebind callback (php-src HAVE_3ARG_SETREBINDPROC; #22226).
 *
 * ldap_set_rebind_proc(LDAP\Connection $ldap, ?callable $callback): bool
 */
final class ldap_set_rebind_proc extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_set_rebind_proc');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_set_rebind_proc() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_set_rebind_proc', 1);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ldap_set_rebind_proc() requires a VM context');
        }
        $cbVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_NULL === $cbVar->type) {
            VmLdapConnection::setRebindProc($conn, null, $ctx);
        } else {
            if (!\PHPCompiler\ext\standard\VmCallable::isCallable($ctx, $cbVar, false, null, $frame)) {
                throw new \TypeError(\sprintf(
                    'ldap_set_rebind_proc(): Argument #2 ($callback) must be of type ?callable, %s given',
                    self::vmTypeName($cbVar->type)
                ));
            }
            VmLdapConnection::setRebindProc($conn, $cbVar, $ctx);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeSetRebindProc($context, $args);
    }
}

final class ldap_unbind extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_unbind');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_unbind() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_unbind', 1);
        $ok = VmLdapConnection::close($conn);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeUnbind($context, $args, 'ldap_unbind');
    }
}

final class ldap_close extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_close() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_close', 1);
        $ok = VmLdapConnection::close($conn);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeUnbind($context, $args, 'ldap_close');
    }
}

final class ldap_errno extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_errno');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_errno() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_errno', 1);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmLdapConnection::errno($conn));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeErrno($context, $args);
    }
}

final class ldap_error extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_error');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_error() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_error', 1);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmLdapNative::err2string(VmLdapConnection::errno($conn)));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeError($context, $args);
    }
}

final class ldap_err2str extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_err2str');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_err2str() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $errnoVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $errnoVar->type) {
            throw new \TypeError('ldap_err2str(): Argument #1 ($errno) must be of type int');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmLdapNative::err2string($errnoVar->toInt()));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeErr2str($context, $args);
    }
}

final class ldap_set_option extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_set_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_set_option() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $ldapVar = $frame->calledArgs[0]->resolveIndirect();
        $ld = null;
        $connObj = null;
        if (Variable::TYPE_NULL !== $ldapVar->type) {
            $connObj = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_set_option', 1);
            $ld = VmLdapConnection::native($connObj);
        }
        $optVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $optVar->type) {
            throw new \TypeError('ldap_set_option(): Argument #2 ($option) must be of type int');
        }
        $option = $optVar->toInt();
        $valueVar = $frame->calledArgs[2]->resolveIndirect();
        $ok = false;
        if (Variable::TYPE_INTEGER === $valueVar->type || Variable::TYPE_BOOLEAN === $valueVar->type) {
            $rc = VmLdapNative::setOptionInt($ld, $option, $valueVar->toInt());
            $ok = VmLdapNative::LDAP_SUCCESS === $rc;
            if (null !== $connObj && !$ok) {
                VmLdapConnection::setErrno($connObj, $rc);
            }
        } else {
            @\trigger_error('ldap_set_option(): Type not supported for this option', \E_USER_WARNING);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeSetOption($context, $args);
    }
}

final class ldap_get_option extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_get_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_get_option() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $ldapVar = $frame->calledArgs[0]->resolveIndirect();
        $ld = null;
        $connObj = null;
        if (Variable::TYPE_NULL !== $ldapVar->type) {
            $connObj = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_get_option', 1);
            $ld = VmLdapConnection::native($connObj);
        }
        $optVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $optVar->type) {
            throw new \TypeError('ldap_get_option(): Argument #2 ($option) must be of type int');
        }
        $option = $optVar->toInt();
        $got = VmLdapNative::getOptionInt($ld, $option);
        if (!$got['ok']) {
            if (null !== $connObj) {
                VmLdapConnection::setErrno($connObj, VmLdapNative::LDAP_OPT_ERROR_NUMBER);
            }
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $frame->calledArgs[2]->byRefTarget()->int($got['value']);
        if (null !== $connObj) {
            VmLdapConnection::setErrno($connObj, VmLdapNative::LDAP_SUCCESS);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeGetOption($context, $args);
    }
}

final class ldap_start_tls extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_start_tls');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_start_tls() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_start_tls', 1);
        $ld = VmLdapConnection::native($conn);
        $rc = VmLdapNative::startTlsSync($ld);
        VmLdapConnection::setErrno($conn, $rc);
        if (VmLdapNative::LDAP_SUCCESS !== $rc) {
            @\trigger_error(
                'ldap_start_tls(): Unable to start TLS: '.VmLdapNative::err2string($rc),
                \E_USER_WARNING
            );
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapLink::invokeStartTls($context, $args);
    }
}
