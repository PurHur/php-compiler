<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomHtmlDocumentCreateFromStringRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPLLVM\Value;

/**
 * LLVM lowering for Dom\HTMLDocument::createFromFile() (#35804).
 *
 * php-src: ext/dom/html_document.c — Dom\HTMLDocument::createFromFile
 *
 * Compile-time path literals are read at emit time and folded through
 * {@see JitDomHtmlDocumentCreateFromString} so thin-AOT materialize stays on
 * the main-module {@code __object__} layout (NestedJIT ObjectEntry is not a
 * thin document — leftover of #27300).
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

        if (null !== $pathLit && '' !== $pathLit) {
            $contents = @\file_get_contents($pathLit);
            if (false === $contents) {
                TypeErrorRaise::emitRaise(
                    $context,
                    'Dom\\HTMLDocument::createFromFile(): failed to load external entity "'.$pathLit.'"'
                );
            } else {
                $sourceArg = self::stringVariableFromLiteral($context, $contents);
                if (null !== $optionsArg) {
                    return JitDomHtmlDocumentCreateFromString::invoke($context, $sourceArg, $optionsArg);
                }

                return JitDomHtmlDocumentCreateFromString::invoke($context, $sourceArg);
            }
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
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureHtmlDocumentCreateFromFileBridge($context);
        } else {
            DomHtmlDocumentCreateFromStringRuntime::ensureLinked($context);
        }

        $document = $context->builder->call(
            $context->lookupFunction(DomHtmlDocumentCreateFromStringRuntime::ABI_FILE),
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

    private static function stringVariableFromLiteral(Context $context, string $str): JITVariable
    {
        $lit = $context->builder->load($context->constantStringFromString($str));
        $var = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $lit);
        $var->compileTimeString = $str;

        return $var;
    }
}
