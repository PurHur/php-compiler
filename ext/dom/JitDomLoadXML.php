<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\DomLoadXMLRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMDocument::loadXML() (#18268, #19796). */
final class JitDomLoadXML
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::loadXML() expects receiver and XML string');
        }

        if (JitDomLoadXMLUserScript::shouldUse($context)) {
            $us = JitDomLoadXMLUserScript::tryInvoke($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }

        $xmlLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null !== $xmlLit && '' !== trim($xmlLit)) {
            JitDomLoadXMLUserScript::rememberCompileTimeXml($xmlLit);
        }

        DomLoadXMLRuntime::ensureLinked($context);

        $document = self::loadObjectArg($context, $args[0]);
        $xmlStr = self::loadStringArg($context, $args[1]);
        $options = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $options = JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'DOMDocument::loadXML()', 2, 'options');
        }
        $raw = $context->builder->call(
            $context->lookupFunction(DomLoadXMLRuntime::ABI_NAME),
            $document,
            $xmlStr,
            $options
        );
        $slot = JitValueBox::alloc($context);
        $i32 = $context->getTypeFromString('int32');
        $boolArg = 'int1' === $context->getStringFromType($raw->typeOf())
            ? $context->builder->zext($raw, $i32)
            : $raw;
        JitValueBox::writeBool($context, $slot, $boolArg);

        return JitValueBox::normalizeValuePtr($context, $slot);
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

        throw new \LogicException('DOMDocument::loadXML() receiver must be an object');
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
