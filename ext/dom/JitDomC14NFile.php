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
 * Thin standalone AOT documentElement / createElement temps are not in NestedJIT
 * DomRegistry. Prefer compile-time host C14N → {@see JitFilePutContents} (peer
 * saveHTMLFile). Fall back to DomC14NFileRuntime when markup is not available.
 *
 * php-src: ext/dom/node.c PHP_METHOD(DOMNode, C14NFile)
 */
final class JitDomC14NFile
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMNode::C14NFile() expects receiver and uri');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_c14nfile_cont');

        $folded = self::tryCompileTimeCreateElementFold($context, ...$args);
        if (null !== $folded) {
            return $folded;
        }

        $folded = self::tryCompileTimeLoadXmlFold($context, ...$args);
        if (null !== $folded) {
            return $folded;
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

    /**
     * createElement + setAttribute (+ appendChild) — NestedJIT DomRegistry empty (#32964 / #32973).
     */
    private static function tryCompileTimeCreateElementFold(Context $context, JITVariable ...$args): ?Value
    {
        $exclusive = self::compileTimeExclusiveFlag($args[2] ?? null);
        $payload = JitDomC14NCompileTime::tryFoldCreateElement($args[0], $exclusive);
        if (null === $payload || false === $payload) {
            return null;
        }

        StringFilePutContents::ensureStandaloneBodies($context);
        $path = self::loadStringArg($context, $args[1]);
        $data = $context->builder->load($context->constantStringFromString($payload));
        $flags = $context->context->int64Type()->constInt(0, false);

        return JitFilePutContents::invoke($context, $path, $data, $flags);
    }

    /**
     * loadXML literal + documentElement receiver — NestedJIT DomRegistry is empty (#32964).
     */
    private static function tryCompileTimeLoadXmlFold(Context $context, JITVariable ...$args): ?Value
    {
        $xml = JitDomLoadXMLUserScript::compileTimeXmlFor($args[0])
            ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return null;
        }
        $exclusive = self::compileTimeExclusiveFlag($args[2] ?? null);
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml) || null === $doc->documentElement) {
            return null;
        }
        $payload = $doc->documentElement->C14N($exclusive, false);
        if (!\is_string($payload)) {
            return null;
        }

        StringFilePutContents::ensureStandaloneBodies($context);
        $path = self::loadStringArg($context, $args[1]);
        $data = $context->builder->load($context->constantStringFromString($payload));
        $flags = $context->context->int64Type()->constInt(0, false);

        return JitFilePutContents::invoke($context, $path, $data, $flags);
    }

    private static function compileTimeExclusiveFlag(?JITVariable $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== $arg->compileTimeLong;
        }

        return false;
    }

    private static function boxIntOrFalse(Context $context, Value $raw): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_SLT, $raw, $zero);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

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

        return $ptr;
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
