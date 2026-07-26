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
 * ldap_mod_* / ldap_add / ldap_delete / ldap_modify / ldap_modify_batch / *_ext / ldap_rename
 * (php-src ext/ldap/ldap.c; #21853 / #22196).
 *
 * Optional $controls accepted and ignored in v1 (same pattern as ldap_exop).
 */

abstract class ldap_modify_base extends Internal
{
    abstract protected function modOperation(): int;

    abstract protected function functionName(): string;

    /** True → ldap_add_ext_s (full entry add); false → ldap_modify_ext_s. */
    protected function isFullAdd(): bool
    {
        return false;
    }

    /** True → async *_ext returning LDAP\Result. */
    protected function isExt(): bool
    {
        return false;
    }

    public function execute(Frame $frame): void
    {
        $name = $this->functionName();
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects between 3 and 4 arguments, %d given',
                $name,
                $argc
            ));
        }
        // arg 4 ($controls) accepted; population deferred (php-src |a!).
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
        if ($this->isExt()) {
            $ctx = $frame->vmContext;
            if (null === $ctx) {
                throw new \LogicException($name.'() requires a VM context');
            }
            $result = VmLdapModify::modifyExt($conn, $dn, $mods, $this->isFullAdd(), $name, $ctx);
            if (false === $result) {
                $frame->returnVar->bool(false);
            } else {
                $frame->returnVar->copyFrom($result);
            }

            return;
        }
        if ($this->isFullAdd()) {
            $ok = VmLdapModify::add($conn, $dn, $mods, $name);
        } else {
            $ok = VmLdapModify::modify($conn, $dn, $mods, $name);
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->functionName().'() is not implemented for JIT in this compiler build (issue #22196)');
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

/** php-src alias of ldap_mod_replace (ext/ldap/ldap.stub.php). */
final class ldap_modify extends ldap_modify_base
{
    public function __construct()
    {
        parent::__construct('ldap_modify');
    }

    protected function modOperation(): int
    {
        return LdapConstants::LDAP_MOD_REPLACE;
    }

    protected function functionName(): string
    {
        return 'ldap_modify';
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

final class ldap_add extends ldap_modify_base
{
    public function __construct()
    {
        parent::__construct('ldap_add');
    }

    protected function modOperation(): int
    {
        return LdapConstants::LDAP_MOD_ADD;
    }

    protected function functionName(): string
    {
        return 'ldap_add';
    }

    protected function isFullAdd(): bool
    {
        return true;
    }
}

final class ldap_add_ext extends ldap_modify_base
{
    public function __construct()
    {
        parent::__construct('ldap_add_ext');
    }

    protected function modOperation(): int
    {
        return LdapConstants::LDAP_MOD_ADD;
    }

    protected function functionName(): string
    {
        return 'ldap_add_ext';
    }

    protected function isFullAdd(): bool
    {
        return true;
    }

    protected function isExt(): bool
    {
        return true;
    }
}

final class ldap_mod_add_ext extends ldap_modify_base
{
    public function __construct()
    {
        parent::__construct('ldap_mod_add_ext');
    }

    protected function modOperation(): int
    {
        return LdapConstants::LDAP_MOD_ADD;
    }

    protected function functionName(): string
    {
        return 'ldap_mod_add_ext';
    }

    protected function isExt(): bool
    {
        return true;
    }
}

final class ldap_mod_replace_ext extends ldap_modify_base
{
    public function __construct()
    {
        parent::__construct('ldap_mod_replace_ext');
    }

    protected function modOperation(): int
    {
        return LdapConstants::LDAP_MOD_REPLACE;
    }

    protected function functionName(): string
    {
        return 'ldap_mod_replace_ext';
    }

    protected function isExt(): bool
    {
        return true;
    }
}

final class ldap_mod_del_ext extends ldap_modify_base
{
    public function __construct()
    {
        parent::__construct('ldap_mod_del_ext');
    }

    protected function modOperation(): int
    {
        return LdapConstants::LDAP_MOD_DELETE;
    }

    protected function functionName(): string
    {
        return 'ldap_mod_del_ext';
    }

    protected function isExt(): bool
    {
        return true;
    }
}

abstract class ldap_modify_batch_base extends Internal
{
    abstract protected function functionName(): string;

    public function execute(Frame $frame): void
    {
        $name = $this->functionName();
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects between 3 and 4 arguments, %d given',
                $name,
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], $name, 1);
        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $name, 1, 'dn');
        $mods = VmLdapModify::batchToMods($frame->calledArgs[2], $name);
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
        throw new \LogicException($this->functionName().'() is not implemented for JIT in this compiler build (issue #22196)');
    }
}

/** Zend name (php-src ldap.stub.php). */
final class ldap_modify_batch extends ldap_modify_batch_base
{
    public function __construct()
    {
        parent::__construct('ldap_modify_batch');
    }

    protected function functionName(): string
    {
        return 'ldap_modify_batch';
    }
}

/** Historical alias kept for #21853 compliance. */
final class ldap_mod_batch extends ldap_modify_batch_base
{
    public function __construct()
    {
        parent::__construct('ldap_mod_batch');
    }

    protected function functionName(): string
    {
        return 'ldap_mod_batch';
    }
}

final class ldap_delete extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_delete');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_delete() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_delete', 1);
        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_delete', 1, 'dn');
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmLdapModify::delete($conn, $dn, 'ldap_delete');
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_delete() is not implemented for JIT in this compiler build (issue #22196)');
    }
}

final class ldap_delete_ext extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_delete_ext');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_delete_ext() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_delete_ext', 1);
        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_delete_ext', 1, 'dn');
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ldap_delete_ext() requires a VM context');
        }
        $result = VmLdapModify::deleteExt($conn, $dn, 'ldap_delete_ext', $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->copyFrom($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_delete_ext() is not implemented for JIT in this compiler build (issue #22196)');
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
        if ($argc < 4 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_rename() expects between 4 and 6 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_rename', 1);
        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_rename', 1, 'dn');
        $newRdn = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ldap_rename', 2, 'new_rdn');
        $newParent = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'ldap_rename', 3, 'new_parent');
        $deleteOld = true;
        if ($argc >= 5) {
            $deleteOld = $frame->calledArgs[4]->resolveIndirect()->toBool();
        }
        // arg 6 ($controls) accepted; ignored in v1.
        if (null === $frame->returnVar) {
            return;
        }
        $ok = VmLdapModify::rename($conn, $dn, $newRdn, $newParent, $deleteOld);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_rename() is not implemented for JIT in this compiler build (issue #22196)');
    }
}

final class ldap_rename_ext extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_rename_ext');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_rename_ext() expects between 5 and 6 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_rename_ext', 1);
        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_rename_ext', 1, 'dn');
        $newRdn = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ldap_rename_ext', 2, 'new_rdn');
        $newParent = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'ldap_rename_ext', 3, 'new_parent');
        $deleteOld = $frame->calledArgs[4]->resolveIndirect()->toBool();
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ldap_rename_ext() requires a VM context');
        }
        $result = VmLdapModify::renameExt($conn, $dn, $newRdn, $newParent, $deleteOld, 'ldap_rename_ext', $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->copyFrom($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_rename_ext() is not implemented for JIT in this compiler build (issue #22196)');
    }
}
