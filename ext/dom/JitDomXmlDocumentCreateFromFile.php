<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomXmlDocumentCreateFromFileRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPLLVM\Value;

/**
 * LLVM lowering for Dom\XMLDocument::createFromFile() (leftover of #27108 / #20808).
 *
 * php-src: ext/dom/xml_document.c — Dom\XMLDocument::createFromFile
 */
final class JitDomXmlDocumentCreateFromFile
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xml_create_from_file_cont');
        if (\count($args) < 1) {
            throw new \ArgumentCountError(
                'Dom\\XMLDocument::createFromFile() expects at least 1 argument, 0 given'
            );
        }

        $pathArg = $args[0];
        $optionsArg = $args[1] ?? null;

        if (JITVariable::TYPE_NULL === $pathArg->type || $pathArg->isNullConstant) {
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'Dom\\XMLDocument::createFromFile',
                0,
                'path'
            );
        }

        $pathLit = JitStringBuiltinArg::compileTimeLiteral($pathArg) ?? $pathArg->compileTimeString;
        if (null !== $pathLit && '' === $pathLit) {
            TypeErrorRaise::emitValueError(
                $context,
                'Dom\\XMLDocument::createFromFile(): Argument #1 ($path) must not be empty'
            );
        }

        if (null !== $pathLit && '' !== $pathLit) {
            $contents = VmFsReadNative::read($pathLit);
            if (false === $contents) {
                throw new \DOMException(
                    'Dom\\XMLDocument::createFromFile(): failed to load external entity "'.$pathLit.'"'
                );
            }
            $source = self::sourceVarFromLiteral($context, $contents);
            if (null !== $optionsArg) {
                return JitDomXmlDocumentCreateFromString::invoke($context, $source, $optionsArg);
            }

            return JitDomXmlDocumentCreateFromString::invoke($context, $source);
        }
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            throw new \LogicException(
                'Dom\\XMLDocument::createFromFile() user-script AOT requires a compile-time path literal'
            );
        }

        return self::invokeViaHelper($context, $pathArg, $optionsArg);
    }

    private static function invokeViaHelper(
        Context $context,
        JITVariable $pathArg,
        ?JITVariable $optionsArg
    ): Value {
        $pathStr = JitStringBuiltinArg::lower(
            $context,
            $pathArg,
            'Dom\\XMLDocument::createFromFile',
            0,
            'path'
        );
        $options = $context->getTypeFromString('int64')->constInt(0, false);
        if (null !== $optionsArg && !NamedOptionalCallArgs::isOmittedOptional($optionsArg)) {
            $options = JitIntdiv::lowerIntBuiltinArg(
                $context,
                $optionsArg,
                'Dom\\XMLDocument::createFromFile()',
                2,
                'options'
            );
        }

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        DomXmlDocumentCreateFromFileRuntime::ensureLinked($context);

        $document = $context->builder->call(
            $context->lookupFunction(DomXmlDocumentCreateFromFileRuntime::ABI_NAME),
            $pathStr,
            $options
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $document
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function sourceVarFromLiteral(Context $context, string $contents): JITVariable
    {
        $loaded = $context->builder->load($context->constantStringFromString($contents));
        $var = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $loaded);
        $var->compileTimeString = $contents;

        return $var;
    }
}
