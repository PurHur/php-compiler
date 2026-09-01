<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitFilePutContents;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomC14NFileRuntime;
use PHPCompiler\JIT\Builtin\StringFilePutContents;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::C14NFile() (#32964 / #32973).
 *
 * Prefer {@see JitDomC14N::tryFoldCanonical} (loadXML documentElement or
 * createElement+setAttribute) then {@see JitFilePutContents}. Fall back to
 * DomC14NFileRuntime for DomRegistry receivers.
 *
 * php-src: ext/dom/node.c PHP_METHOD(DOMNode, C14NFile)
 */
final class JitDomC14NFile
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_c14nfile_cont');
        $argcDummy = DomJitArgc::rejectUnlessUserArgCountRange(
            $context,
            $args,
            'DOMNode::C14NFile',
            1,
            5
        );
        if (null !== $argcDummy) {
            return $argcDummy;
        }
        if (\count($args) < 2) {
            throw new \LogicException('DOMNode::C14NFile() expects receiver and uri');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_c14nfile_cont');

        $folded = JitDomC14N::tryFoldCanonical($args[0], $args[2] ?? null);
        if (\is_string($folded)) {
            StringFilePutContents::ensureStandaloneBodies($context);
            $path = self::loadStringArg($context, $args[1]);
            $data = $context->builder->load($context->constantStringFromString($folded));
            $flags = $context->context->int64Type()->constInt(0, false);

            return JitFilePutContents::invoke($context, $path, $data, $flags);
        }
        if (false === $folded) {
            return self::boxBoolFalse($context);
        }

        DomC14NFileRuntime::ensureLinked($context);
        $raw = $context->builder->call(
            $context->lookupFunction(DomC14NFileRuntime::ABI_NAME),
            self::loadObjectArg($context, $args[0]),
            self::loadStringArg($context, $args[1]),
            self::exclusiveAsI64($context, $args[2] ?? null)
        );

        return self::boxIntOrFalse($context, $raw);
    }

    private static function boxBoolFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxIntOrFalse(Context $context, Value $raw): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_SLT, $raw, $zero);

        $slot = JitValueBox::alloc($context);

        $failBlock = BasicBlockHelper::append($context, 'dom_c14nfile_fail');
        $okBlock = BasicBlockHelper::append($context, 'dom_c14nfile_ok');
        $doneBlock = BasicBlockHelper::append($context, 'dom_c14nfile_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $raw);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return JitValueBox::pointer($context, $slot);
    }

    private static function exclusiveAsI64(Context $context, ?JITVariable $arg): Value
    {
        if (null === $arg) {
            return $context->context->int64Type()->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            $raw = $context->helper->loadValue($arg);
            if (method_exists($raw, 'isConstant') && $raw->isConstant() && method_exists($raw, 'getConstantValue')) {
                return $context->context->int64Type()->constInt(
                    ((int) $raw->getConstantValue() !== 0) ? 1 : 0,
                    false
                );
            }

            return $context->builder->zExt($raw, $context->context->int64Type());
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== $arg->compileTimeLong) {
            return $context->context->int64Type()->constInt(0 !== $arg->compileTimeLong ? 1 : 0, false);
        }

        return $context->context->int64Type()->constInt(0, false);
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMNode::C14NFile() receiver must be an object');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
    }
}
