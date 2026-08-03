<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomXmlDocumentCreateFromStringRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPLLVM\Value;

/**
 * LLVM lowering for Dom\XMLDocument::createFromString() (#27108, #19581).
 *
 * php-src: ext/dom/xml_document.c — Dom\XMLDocument::createFromString
 */
final class JitDomXmlDocumentCreateFromString
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xml_create_from_string_cont');
        if (\count($args) < 1) {
            throw new \ArgumentCountError(
                'Dom\\XMLDocument::createFromString() expects at least 1 argument, 0 given'
            );
        }

        $sourceArg = $args[0];
        $optionsArg = $args[1] ?? null;

        if (JITVariable::TYPE_NULL === $sourceArg->type || $sourceArg->isNullConstant) {
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'Dom\\XMLDocument::createFromString',
                0,
                'source'
            );
        }

        $sourceLit = JitStringBuiltinArg::compileTimeLiteral($sourceArg) ?? $sourceArg->compileTimeString;
        if (null !== $sourceLit && '' === $sourceLit) {
            TypeErrorRaise::emitValueError(
                $context,
                'Dom\\XMLDocument::createFromString(): Argument #1 ($source) must not be empty'
            );
        }

        $xmlStr = JitStringBuiltinArg::lower(
            $context,
            $sourceArg,
            'Dom\\XMLDocument::createFromString',
            0,
            'source'
        );
        $options = $context->getTypeFromString('int64')->constInt(0, false);
        if (null !== $optionsArg && !NamedOptionalCallArgs::isOmittedOptional($optionsArg)) {
            $options = JitIntdiv::lowerIntBuiltinArg(
                $context,
                $optionsArg,
                'Dom\\XMLDocument::createFromString()',
                2,
                'options'
            );
        }

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        DomXmlDocumentCreateFromStringRuntime::ensureLinked($context);

        $document = $context->builder->call(
            $context->lookupFunction(DomXmlDocumentCreateFromStringRuntime::ABI_NAME),
            $xmlStr,
            $options
        );
        // Box in the caller — ABI returns __object__* (do not return callee alloca value*).
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $document
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
