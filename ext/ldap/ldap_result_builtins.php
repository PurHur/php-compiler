<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * ldap_compare / ldap_parse_result / ldap_get_dn / attribute walk (php-src ext/ldap/ldap.c; #22177).
 */

final class ldap_compare extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_compare');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_compare() expects between 4 and 5 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_compare', 1);
        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'ldap_compare', 1, 'dn');
        $attr = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ldap_compare', 2, 'attribute');
        $value = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'ldap_compare', 3, 'value');
        // arg 5 ($controls) accepted; ignored in v1.
        if (null === $frame->returnVar) {
            return;
        }
        $r = VmLdapCore::compare($conn, $dn, $attr, $value);
        if (\is_bool($r)) {
            $frame->returnVar->bool($r);
        } else {
            $frame->returnVar->int($r);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapResult::invokeCompare($context, $args);
    }
}

final class ldap_parse_result extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_parse_result');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 7) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_parse_result() expects between 3 and 7 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_parse_result', 1);
        $result = VmLdapArg::requireResult($frame->calledArgs[1], 'ldap_parse_result', 2);
        $parsed = VmLdapCore::parseResult(
            $conn,
            $result,
            $argc > 3,
            $argc > 4,
            $argc > 5
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $parsed) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->calledArgs[2]->resolveIndirect()->int($parsed['errcode']);
        if ($argc > 3) {
            $frame->calledArgs[3]->resolveIndirect()->string($parsed['matched_dn']);
        }
        if ($argc > 4) {
            $frame->calledArgs[4]->resolveIndirect()->string($parsed['error_message']);
        }
        if ($argc > 5) {
            $ht = new HashTable();
            foreach ($parsed['referrals'] as $i => $ref) {
                $slot = new Variable();
                $slot->string($ref);
                $ht->addIndex($i, $slot);
            }
            $frame->calledArgs[5]->resolveIndirect()->array($ht);
        }
        // arg 7 ($controls by ref) accepted; population deferred.
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_parse_result() is not implemented for JIT in this compiler build (issue #22177)');
    }
}

final class ldap_get_dn extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_get_dn');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_get_dn() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_get_dn', 1);
        $entry = VmLdapArg::requireEntry($frame->calledArgs[1], 'ldap_get_dn', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $dn = VmLdapCore::getDn($conn, $entry);
        if (false === $dn) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($dn);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_get_dn() is not implemented for JIT in this compiler build (issue #22177)');
    }
}

final class ldap_first_attribute extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_first_attribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_first_attribute() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_first_attribute', 1);
        $entry = VmLdapArg::requireEntry($frame->calledArgs[1], 'ldap_first_attribute', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $attr = VmLdapCore::firstAttribute($conn, $entry);
        if (false === $attr) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($attr);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_first_attribute() is not implemented for JIT in this compiler build (issue #22177)');
    }
}

final class ldap_next_attribute extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_next_attribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_next_attribute() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_next_attribute', 1);
        $entry = VmLdapArg::requireEntry($frame->calledArgs[1], 'ldap_next_attribute', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $attr = VmLdapCore::nextAttribute($conn, $entry);
        if (false === $attr) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($attr);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_next_attribute() is not implemented for JIT in this compiler build (issue #22177)');
    }
}

final class ldap_get_values extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_get_values');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_get_values() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_get_values', 1);
        $entry = VmLdapArg::requireEntry($frame->calledArgs[1], 'ldap_get_values', 2);
        $attr = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ldap_get_values', 2, 'attribute');
        if (null === $frame->returnVar) {
            return;
        }
        $values = VmLdapCore::getValues($conn, $entry, $attr);
        if (false === $values) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->copyFrom($values);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_get_values() is not implemented for JIT in this compiler build (issue #22177)');
    }
}

/** Alias of ldap_get_values (php-src ldap.stub.php). */
final class ldap_get_values_len extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_get_values_len');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_get_values_len() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_get_values_len', 1);
        $entry = VmLdapArg::requireEntry($frame->calledArgs[1], 'ldap_get_values_len', 2);
        $attr = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'ldap_get_values_len', 2, 'attribute');
        if (null === $frame->returnVar) {
            return;
        }
        $values = VmLdapCore::getValues($conn, $entry, $attr);
        if (false === $values) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->copyFrom($values);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_get_values_len() is not implemented for JIT in this compiler build (issue #22177)');
    }
}
