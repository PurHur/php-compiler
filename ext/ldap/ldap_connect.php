<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\LdapRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ldap_connect() — ldap_initialize (php-src ext/ldap/ldap.c; #3369 / #32000).
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
        return JitLdapConnect::invoke($context, $args);
    }
}

/** LLVM lowering for ldap_connect() via LdapDnJitHelper (#32000). */
final class JitLdapConnect
{
    /** @param list<JITVariable> $args */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_connect() expects at most 2 arguments, %d given',
                $argc
            ));
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        if ($argc >= 1) {
            $uri = JitStringBuiltinArg::lowerNullableString(
                $context,
                $args[0],
                'ldap_connect',
                0,
                'uri'
            );
        } else {
            $uri = $strPtr->constNull();
        }
        if (2 === $argc) {
            $hasPort = $i64->constInt(1, false);
            $port = JitIntdiv::lowerIntBuiltinArg(
                $context,
                $args[1],
                'ldap_connect',
                2,
                'port'
            );
        } else {
            $hasPort = $i64->constInt(0, false);
            $port = $i64->constInt(0, false);
        }

        LdapRuntime::ensureLinked($context);

        $result = $context->builder->call(
            $context->lookupFunction('__compiler_ldap_connect'),
            $uri,
            $hasPort,
            $port
        );

        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($result, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $regBb = BasicBlockHelper::append($context, 'ldap_connect_register');
        $doneBb = BasicBlockHelper::append($context, 'ldap_connect_done');
        $context->builder->branchIf($isObject, $regBb, $doneBb);

        $context->builder->positionAtEnd($regBb);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $result
        );
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $objAddr = $context->builder->ptrToInt(
            $context->builder->pointerCast($obj, $voidp),
            $i64
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_ldap_link_register'),
            $objAddr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $result;
    }
}
