<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomHtmlDocumentCreateFromFileRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPLLVM\Value;

/**
 * LLVM lowering for Dom\HTMLDocument::createFromFile() (leftover of #27300 / #19580).
 *
 * php-src: ext/dom/html_document.c — Dom\HTMLDocument::createFromFile
 */
final class JitDomHtmlDocumentCreateFromFile
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_html_create_from_file_cont');
        if (\count($args) < 1) {
            throw new \ArgumentCountError(
                'Dom\\HTMLDocument::createFromFile() expects at least 1 argument, 0 given'
            );
        }

        $pathArg = $args[0];
        $optionsArg = $args[1] ?? null;

        if (JITVariable::TYPE_NULL === $pathArg->type || $pathArg->isNullConstant) {
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'Dom\\HTMLDocument::createFromFile',
                0,
                'path'
            );
        }

        $pathLit = JitStringBuiltinArg::compileTimeLiteral($pathArg) ?? $pathArg->compileTimeString;
        if (null !== $pathLit && '' === $pathLit) {
            TypeErrorRaise::emitValueError(
                $context,
                'Dom\\HTMLDocument::createFromFile(): Argument #1 ($path) must not be empty'
            );
        }

        // Thin AOT NestedJIT ObjectEntry* is not a main-module __object__ (CFS runtime
        // createFromString SIGSEGVs the same way). Fold compile-time paths through
        // createFromString materialize (php-src html_document.c load-file → parse).
        if (null !== $pathLit && '' !== $pathLit) {
            $contents = VmFsReadNative::read($pathLit);
            if (false === $contents) {
                throw new \DOMException(
                    'Dom\\HTMLDocument::createFromFile(): failed to load external entity "'.$pathLit.'"'
                );
            }

            $source = self::sourceVarFromLiteral($context, $contents);
            if (null !== $optionsArg) {
                return JitDomHtmlDocumentCreateFromString::invoke($context, $source, $optionsArg);
            }

            return JitDomHtmlDocumentCreateFromString::invoke($context, $source);
        }
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            throw new \LogicException(
                'Dom\\HTMLDocument::createFromFile() user-script AOT requires a compile-time path literal'
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
            'Dom\\HTMLDocument::createFromFile',
            0,
            'path'
        );
        $options = $context->getTypeFromString('int64')->constInt(0, false);
        if (null !== $optionsArg && !NamedOptionalCallArgs::isOmittedOptional($optionsArg)) {
            $options = JitIntdiv::lowerIntBuiltinArg(
                $context,
                $optionsArg,
                'Dom\\HTMLDocument::createFromFile()',
                2,
                'options'
            );
        }

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        DomHtmlDocumentCreateFromFileRuntime::ensureLinked($context);

        $document = $context->builder->call(
            $context->lookupFunction(DomHtmlDocumentCreateFromFileRuntime::ABI_NAME),
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
