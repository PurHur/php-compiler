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
 * ldap_exop* / ldap_parse_exop (php-src ext/ldap/ldap.c; #8688).
 */

abstract class ldap_exop_base extends Internal
{
    abstract protected function forceSync(): bool;

    public function execute(Frame $frame): void
    {
        $name = $this->getName();
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects between 2 and 6 arguments, %d given',
                $name,
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], $name, 1);
        $oid = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $name, 1, 'request_oid');
        $data = null;
        if ($argc >= 3 && Variable::TYPE_NULL !== $frame->calledArgs[2]->resolveIndirect()->type) {
            $data = VmString::coerceStringBuiltinArg($frame->calledArgs[2], $name, 2, 'request_data');
        }
        // controls (arg 4) ignored in v1 — php-src accepts null/array; we accept and skip.
        $wantSync = $this->forceSync() || $argc >= 5;
        $ld = VmLdapConnection::native($conn);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException($name.'() requires a VM context');
        }

        if ($wantSync) {
            $parsed = VmLdapNative::extendedOperationSync($ld, $oid, $data);
            VmLdapConnection::setErrno($conn, $parsed['errno']);
            if (!$parsed['ok']) {
                @\trigger_error(
                    \sprintf('%s(): Extended operation %s failed: %s (%d)', $name, $oid, VmLdapNative::err2string($parsed['errno']), $parsed['errno']),
                    \E_USER_WARNING
                );
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            if ($argc >= 5) {
                $frame->calledArgs[4]->resolveIndirect()->string($parsed['data']);
            }
            if ($argc >= 6) {
                $frame->calledArgs[5]->resolveIndirect()->string($parsed['oid']);
            }
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }

        $resInfo = VmLdapNative::extendedOperationAsync($ld, $oid, $data);
        $res = $resInfo['result'];
        if (null === $res) {
            $errno = $resInfo['errno'];
            VmLdapConnection::setErrno($conn, $errno);
            @\trigger_error(
                \sprintf('%s(): Extended operation %s failed: %s (%d)', $name, $oid, VmLdapNative::err2string($errno), $errno),
                \E_USER_WARNING
            );
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        VmLdapConnection::setErrno($conn, VmLdapNative::LDAP_SUCCESS);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(VmLdapResult::wrapResult($res, $ctx, $conn));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #8688)');
    }
}

final class ldap_exop extends ldap_exop_base
{
    public function __construct()
    {
        parent::__construct('ldap_exop');
    }

    protected function forceSync(): bool
    {
        return false;
    }
}

final class ldap_exop_sync extends ldap_exop_base
{
    public function __construct()
    {
        parent::__construct('ldap_exop_sync');
    }

    protected function forceSync(): bool
    {
        return true;
    }
}

final class ldap_parse_exop extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_parse_exop');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_parse_exop() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_parse_exop', 1);
        $result = VmLdapArg::requireResult($frame->calledArgs[1], 'ldap_parse_exop', 2);
        $parsed = VmLdapNative::parseExtendedResult(
            VmLdapConnection::native($conn),
            VmLdapResult::resultNative($result)
        );
        VmLdapConnection::setErrno($conn, $parsed['errno']);
        if (!$parsed['ok']) {
            @\trigger_error(
                'ldap_parse_exop(): Unable to parse extended operation result: '.VmLdapNative::err2string($parsed['errno']),
                \E_USER_WARNING
            );
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        if ($argc >= 3) {
            $frame->calledArgs[2]->resolveIndirect()->string($parsed['data']);
        }
        if ($argc >= 4) {
            $frame->calledArgs[3]->resolveIndirect()->string($parsed['oid']);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_parse_exop() is not implemented for JIT in this compiler build (issue #8688)');
    }
}

final class ldap_exop_passwd extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_exop_passwd');
    }

    public function execute(Frame $frame): void
    {
        $name = $this->getName();
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects between 1 and 5 arguments, %d given',
                $name,
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], $name, 1);
        $user = '';
        if ($argc >= 2) {
            $user = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $name, 1, 'user');
        }
        $oldPw = '';
        if ($argc >= 3) {
            $oldPw = VmString::coerceStringBuiltinArg($frame->calledArgs[2], $name, 2, 'old_password');
        }
        $newPw = '';
        if ($argc >= 4) {
            $newPw = VmString::coerceStringBuiltinArg($frame->calledArgs[3], $name, 3, 'new_password');
        }
        // arg 5 ($controls by ref) accepted; population deferred (#8688 controls pattern).
        $ld = VmLdapConnection::native($conn);
        $parsed = VmLdapNative::passwdModifySync($ld, $user, $oldPw, $newPw);
        if (!$parsed['ok']) {
            $errno = $parsed['errno'];
            VmLdapConnection::setErrno($conn, $errno);
            $msg = $parsed['errmsg'] ?? VmLdapNative::err2string($errno);
            @\trigger_error(
                \sprintf('%s(): Passwd modify extended operation failed: %s (%d)', $name, $msg, $errno),
                \E_USER_WARNING
            );
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        VmLdapConnection::setErrno($conn, VmLdapNative::LDAP_SUCCESS);
        if (null !== $frame->returnVar) {
            $val = $parsed['value'];
            if (\is_bool($val)) {
                $frame->returnVar->bool($val);
            } else {
                $frame->returnVar->string($val);
            }
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_exop_passwd() is not implemented for JIT in this compiler build (issue #8688)');
    }
}

final class ldap_exop_refresh extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_exop_refresh');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_exop_refresh() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_exop_refresh', 1);
        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_exop_refresh', 1, 'dn');
        $ttlVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $ttlVar->type) {
            throw new \TypeError('ldap_exop_refresh(): Argument #3 ($ttl) must be of type int');
        }
        $ttl = $ttlVar->toInt();
        $ld = VmLdapConnection::native($conn);
        $ref = VmLdapNative::refreshSync($ld, $dn, $ttl);
        if (!$ref['ok']) {
            VmLdapConnection::setErrno($conn, $ref['errno']);
            @\trigger_error(
                \sprintf('ldap_exop_refresh(): Refresh extended operation failed: %s (%d)', VmLdapNative::err2string($ref['errno']), $ref['errno']),
                \E_USER_WARNING
            );
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        VmLdapConnection::setErrno($conn, VmLdapNative::LDAP_SUCCESS);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($ref['ttl']);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_exop_refresh() is not implemented for JIT in this compiler build (issue #8688)');
    }
}
