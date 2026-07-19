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
 * ldap_connect() — ldap_initialize (php-src ext/ldap/ldap.c; #3369).
 */
final class ldap_connect extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_connect() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $uri = null;
        if ($argc >= 1 && Variable::TYPE_NULL !== $frame->calledArgs[0]->resolveIndirect()->type) {
            $uri = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'ldap_connect', 0, 'uri');
        }
        $port = null;
        if (2 === $argc) {
            $portVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $portVar->type) {
                throw new \TypeError(\sprintf(
                    'ldap_connect(): Argument #2 ($port) must be of type int, %s given',
                    $portVar->type === Variable::TYPE_NULL ? 'null' : 'mixed'
                ));
            }
            $port = $portVar->toInt();
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ldap_connect() requires a VM context');
        }
        $result = VmLdapCore::connect($uri, $port, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_connect() is not implemented for JIT in this compiler build (issue #3369)');
    }
}
