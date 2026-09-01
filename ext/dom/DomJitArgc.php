<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * Shared AOT argc guards when compile-time lowering drops surplus call operands (#30814).
 *
 * php-src: ext/dom/* ZEND_PARSE_PARAMETERS / stub arity
 */
final class DomJitArgc
{
    /**
     * @param JITVariable[] $args
     */
    public static function rejectUnlessExactUserArgCount(
        Context $context,
        array $args,
        string $function,
        int $expected
    ): ?Value {
        $given = VmClassMethod::jitUserArgCount($context, $args);
        if ($given === $expected) {
            return null;
        }
        ExceptionBridge::emitArgumentCountErrorAndAbort(
            $context,
            DomClassMethod::exactUserArgCountMessage($function, $expected, $given)
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_jit_ace_cont');

        return VmClassMethod::jitArgcDummyReturn($context);
    }

    /**
     * @param JITVariable[] $args
     */
    public static function rejectUnlessUserArgCountRange(
        Context $context,
        array $args,
        string $function,
        int $minimum,
        int $maximum
    ): ?Value {
        if (VmClassMethod::requireJitUserArgCountRange($context, $args, $function, $minimum, $maximum)) {
            return null;
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_jit_ace_cont');

        return VmClassMethod::jitArgcDummyReturn($context);
    }

    /**
     * Zend ChildNode::remove() ACE class::method — mirrors {@see DomClassMethod::childNodeRemoveFunction}.
     */
    public static function childNodeRemoveAceFunction(Context $context, JITVariable $receiver): string
    {
        $tag = $receiver->compileTimeDomTagName ?? null;
        if (null !== $tag) {
            if ('#text' === $tag || '#comment' === $tag || '#cdata-section' === $tag) {
                return 'DOMCharacterData::remove';
            }
            if ('' !== $tag && !str_starts_with($tag, '#')) {
                return str_starts_with(strtolower(ltrim((string) ($receiver->classUserType ?? ''), '\\')), 'dom\\')
                    ? ltrim((string) $receiver->classUserType, '\\').'::remove'
                    : 'DOMElement::remove';
            }
        }
        $className = JitOperandTypeLabel::compileTimeObjectClassName($context, $receiver)
            ?? (is_string($receiver->classUserType) && '' !== $receiver->classUserType
                ? ltrim($receiver->classUserType, '\\')
                : null);
        if (null === $className) {
            return 'DOMNode::remove';
        }
        $lc = strtolower(ltrim($className, '\\'));
        if (self::isCharacterDataClassLc($lc)) {
            if (str_starts_with($lc, 'dom\\')) {
                return 'DOMElement::remove';
            }

            return 'DOMCharacterData::remove';
        }
        if (self::isElementClassLc($lc)) {
            if (str_starts_with($lc, 'dom\\')) {
                return $className.'::remove';
            }

            return 'DOMElement::remove';
        }

        return $className.'::remove';
    }

    private static function isCharacterDataClassLc(string $lc): bool
    {
        return in_array($lc, [
            'domcharacterdata',
            'domtext',
            'domcomment',
            'domcdatasection',
        ], true)
            || (str_starts_with($lc, 'dom\\') && (
                str_contains($lc, 'characterdata')
                || str_contains($lc, 'text')
                || str_contains($lc, 'comment')
                || str_contains($lc, 'cdata')
            ));
    }

    private static function isElementClassLc(string $lc): bool
    {
        return 'domelement' === $lc
            || str_ends_with($lc, 'element')
            || (str_starts_with($lc, 'dom\\') && str_contains($lc, 'element'));
    }
}
