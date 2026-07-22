<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ldap_dn2ufn() / ldap_explode_dn() (php-src ext/ldap/ldap.c; #22212).
 */

final class ldap_dn2ufn extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_dn2ufn');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_dn2ufn() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'ldap_dn2ufn', 0, 'dn');
        $result = VmLdapDn::dn2ufn($dn);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapDn::invokeDn2ufn($context, $args);
    }
}

final class ldap_explode_dn extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_explode_dn');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_explode_dn() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $dn = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'ldap_explode_dn', 0, 'dn');
        $withAttrib = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'ldap_explode_dn', 2, 'with_attrib');
        $result = VmLdapDn::explodeDn($dn, $withAttrib);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmLdapDn::toHashTable($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapDn::invokeExplodeDn($context, $args);
    }
}
