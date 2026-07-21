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
 * ldap_mod_* / ldap_rename (php-src ext/ldap/ldap.c; #21853).
 */

abstract class ldap_modify_base extends Internal
{
    abstract protected function modOperation(): int;

    abstract protected function functionName(): string;

    public function execute(Frame $frame): void
    {
        $name = $this->functionName();
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 3 arguments, %d given',
                $name,
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], $name, 1);
        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $name, 1, 'dn');
        $mods = VmLdapModify::entryArrayToMods($frame->calledArgs[2], $this->modOperation(), $name);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $mods) {
            $frame->returnVar->bool(false);

            return;
        }
        $ok = VmLdapModify::modify($conn, $dn, $mods, $name);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->functionName().'() is not implemented for JIT in this compiler build (issue #21853)');
    }
}

final class ldap_mod_add extends ldap_modify_base
{
    public function __construct()
    {
        parent::__construct('ldap_mod_add');
    }

    protected function modOperation(): int
    {
        return LdapConstants::LDAP_MOD_ADD;
    }

    protected function functionName(): string
    {
        return 'ldap_mod_add';
    }
}

final class ldap_mod_replace extends ldap_modify_base
{
    public function __construct()
    {
        parent::__construct('ldap_mod_replace');
    }

    protected function modOperation(): int
    {
        return LdapConstants::LDAP_MOD_REPLACE;
    }

    protected function functionName(): string
    {
        return 'ldap_mod_replace';
    }
}

final class ldap_mod_del extends ldap_modify_base
{
    public function __construct()
    {
        parent::__construct('ldap_mod_del');
    }

    protected function modOperation(): int
    {
        return LdapConstants::LDAP_MOD_DELETE;
    }

    protected function functionName(): string
    {
        return 'ldap_mod_del';
    }
}

final class ldap_mod_batch extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_mod_batch');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_mod_batch() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_mod_batch', 1);
        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_mod_batch', 1, 'dn');
        $mods = VmLdapModify::batchToMods($frame->calledArgs[2], 'ldap_mod_batch');
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $mods) {
            $frame->returnVar->bool(false);

            return;
        }
        $ok = VmLdapModify::modify($conn, $dn, $mods, 'ldap_mod_batch');
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_mod_batch() is not implemented for JIT in this compiler build (issue #21853)');
    }
}

final class ldap_rename extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_rename');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_rename() expects between 4 and 5 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_rename', 1);
        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_rename', 1, 'dn');
        $newRdn = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ldap_rename', 2, 'newrdn');
        $newParent = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'ldap_rename', 3, 'newparent');
        $deleteOld = true;
        if ($argc >= 5) {
            $deleteOld = $frame->calledArgs[4]->resolveIndirect()->toBool();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmLdapModify::rename($conn, $dn, $newRdn, $newParent, $deleteOld);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_rename() is not implemented for JIT in this compiler build (issue #21853)');
    }
}
