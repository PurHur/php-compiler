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
        throw new \LogicException('ldap_bind() is not implemented for JIT in this compiler build (issue #3369)');
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
        throw new \LogicException('ldap_unbind() is not implemented for JIT in this compiler build (issue #3369)');
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
        throw new \LogicException('ldap_close() is not implemented for JIT in this compiler build (issue #3369)');
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
        throw new \LogicException('ldap_errno() is not implemented for JIT in this compiler build (issue #3369)');
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
        throw new \LogicException('ldap_error() is not implemented for JIT in this compiler build (issue #3369)');
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
        throw new \LogicException('ldap_err2str() is not implemented for JIT in this compiler build (issue #3369)');
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
        throw new \LogicException('ldap_set_option() is not implemented for JIT in this compiler build (issue #3369)');
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
        throw new \LogicException('ldap_get_option() is not implemented for JIT in this compiler build (issue #21851)');
    }
}
