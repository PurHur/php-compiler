<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for Dom\HTMLDocument::saveHtml() (#31324, #19580).
 *
 * php-src: ext/dom/html_document.c — Dom\HTMLDocument::saveHtml
 *
 * Thin standalone AOT: user-script createFromString docs are main-module
 * {@code __object__} layouts (no DomRegistry). NestedJIT
 * {@see DomInstanceMethodRuntime} aborts on those receivers — fold the dump
 * at compile time via {@see VmDomLiving::createFromString}+{@see VmDomLiving::saveHtml}
 * on {@code runtime->vmContext}, using the CFS source remembered by
 * {@see JitDomHtmlDocumentCreateFromString}.
 */
final class JitDomHtmlDocumentSaveHtml
{
    private static ?string $lastCreateFromStringSource = null;

    private static int $lastCreateFromStringOptions = 0;

    private static ?string $lastCreateElementTag = null;

    public static function rememberCreateFromString(string $source, int $options = 0): void
    {
        self::$lastCreateFromStringSource = $source;
        self::$lastCreateFromStringOptions = $options;
    }

    public static function lastCreateFromStringSource(): ?string
    {
        return self::$lastCreateFromStringSource;
    }

    public static function lastCreateFromStringOptions(): int
    {
        return self::$lastCreateFromStringOptions;
    }

    /** Remember living createElement() literal for node-scoped saveHtml (#31324 / #31304). */
    public static function rememberCreateElementTag(string $tag): void
    {
        self::$lastCreateElementTag = $tag;
    }

    public static function lastCreateElementTag(): ?string
    {
        return self::$lastCreateElementTag;
    }

    /**
     * Options that only suppress parse diagnostics — tree matches options=0 (#31324).
     *
     * {@see LibxmlConstants::LIBXML_NOERROR} is the HTML factory suppression bit
     * used by living Dom\HTMLDocument fixtures (peer php-src html_document.c).
     */
    public static function optionsAllowUserScriptMaterialize(int $options): bool
    {
        return 0 === ($options & ~LibxmlConstants::LIBXML_NOERROR);
    }

    /**
     * @param JITVariable ...$args document [, node]
     */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('Dom\\HTMLDocument::saveHtml() expects receiver');
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $folded = self::tryFoldFromCreateFromString($context, $args);
            if (null !== $folded) {
                return $folded;
            }
        }

        // DomRegistry / NestedJIT document (non-US helper path).
        $extra = \array_slice($args, 1);

        return DomInstanceMethodRuntime::invoke(
            $context,
            \count($extra),
            'savehtml',
            $args[0],
            ...$extra
        );
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryFoldFromCreateFromString(Context $context, array $args): ?Value
    {
        $source = self::$lastCreateFromStringSource;
        if (null === $source || '' === $source) {
            return null;
        }
        $vm = $context->runtime->vmContext ?? null;
        if (null === $vm) {
            return null;
        }

        $nodeScoped = \count($args) >= 2 && !NamedOptionalCallArgs::isOmittedOptional($args[1]);
        $nodeIsNull = false;
        if ($nodeScoped) {
            $nodeArg = $args[1];
            $nodeIsNull = JITVariable::TYPE_NULL === $nodeArg->type
                || ($nodeArg->isNullConstant ?? false);
        }

        $options = self::$lastCreateFromStringOptions | LibxmlConstants::LIBXML_NOERROR;
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $docVar = VmDomLiving::createFromString($vm, $source, $options);
            if (VmVariable::TYPE_OBJECT !== $docVar->type) {
                return null;
            }
            $document = $docVar->toObject();
            $node = null;
            if ($nodeScoped && !$nodeIsNull) {
                $tag = self::$lastCreateElementTag;
                if (null === $tag || '' === $tag) {
                    return null;
                }
                // Mirror living createElement localName (HTML uppercases display tagName).
                $created = VmDom::createElement($vm, $tag, $document);
                if (VmVariable::TYPE_OBJECT !== $created->type) {
                    return null;
                }
                $node = $created->toObject();
            }
            $html = VmDomLiving::saveHtml($document, $node);

            return self::boxConstantString($context, $html);
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }

    private static function boxConstantString(Context $context, string $lit): Value
    {
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
