<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GethostbynamelRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPLLVM\Value;

/**
 * gethostbynamel() — IPv4 address list for hostname (ext/standard/dns.c parity, #3707).
 *
 * Z_PARAM_STR $hostname — null TypeError under caller strict_types only; PROFILE=8.4 soft-null DEP+false
 * (#24966 / sibling of #24965 gethostbyname; php-src ext/standard/dns.c).
 *
 * VM: VmDns (libc FFI, #4928). JIT/AOT: GethostbynamelRuntime → GethostbynamelJitHelper PHP (#9382).
 * Excess/missing argc → Zend ArgumentCountError (#30585).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(gethostbynamel)
 */
final class gethostbynamel extends Internal
{
    public function __construct()
    {
        parent::__construct('gethostbynamel');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 (#30585; ext/standard/dns.c).
        $this->requireExactArgCount($frame, 'gethostbynamel', 1);
        $hostname = VmString::trimFamilyStringArgForFrame($frame, 0, 'gethostbynamel', 0, 'hostname');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmDns::gethostbynamel($hostname);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30585).
        if (!$this->requireExactJitArgCount($context, $args, 'gethostbynamel', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        $hostnameArg = $args[0];
        $nullOperand = JITVariable::TYPE_NULL === $hostnameArg->type
            || ($hostnameArg->isNullConstant ?? false);
        if ($nullOperand && $context->callerStrictTypes) {
            JitStringBuiltinArg::lowerZparamStr(
                $context,
                $hostnameArg,
                'gethostbynamel',
                0,
                'hostname'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        // Compile-time null → false without DNS helper / DEP IR (AOT-safe fold; VM emits DEP) (#24966).
        if ($nullOperand) {
            return self::boxedFalse($context);
        }

        $literal = $hostnameArg->compileTimeString ?? JitStringArg::compileTimeLiteral($hostnameArg);
        if (null !== $literal) {
            $result = VmDns::gethostbynamel($literal);
            if (false === $result) {
                return self::boxedFalse($context);
            }

            return self::boxedMaterializedList($context, self::materializeIpList($context, $result));
        }

        GethostbynamelRuntime::ensureLinked($context);
        $hostname = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $hostnameArg,
            'gethostbynamel',
            0,
            'hostname'
        );

        return JitGethostbynamel::invoke($context, $hostname);
    }

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }

    private static function materializeIpList(Context $context, HashTable $list): Value
    {
        $ht = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $setAt = $context->lookupFunction('__hashtable__setStringAt');
        $index = 0;
        foreach ($list->iterateKeyed(true) as $pair) {
            [, $var] = $pair;
            $var = $var->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_STRING !== $var->type) {
                continue;
            }
            $ip = $context->builder->load($context->constantStringFromString($var->toString()));
            $context->builder->call($setAt, $ht, $i64->constInt($index, false), $ip);
            ++$index;
        }

        return $ht;
    }

    private static function boxedMaterializedList(Context $context, Value $listHt): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $listHt
        );

        return $ptr;
    }
}
