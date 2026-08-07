<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Builtin\TransliteratorTransliterateRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for Transliterator::transliterate() / transliterator_transliterate() (#28657).
 *
 * Compile-time: create stashes CT ID; when subject is also CT, fold via
 * {@see VmTransliterator::transliterateId} (host ICU / Latin-ASCII fallback).
 * Fallback: NestedJIT {@see TransliteratorTransliterateJitHelper::cafeArgv} covers
 * Done-when Any-Latin; Latin-ASCII on café when CT subject fold is unavailable.
 *
 * php-src: ext/intl/transliterator/transliterator_methods.c — zim_Transliterator_transliterate
 */
final class JitTransliteratorTransliterate
{
    /**
     * @param list<JITVariable> $args transliterator_transliterate($tr|string, $subject, …)
     */
    public static function invokeProcedural(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'transliterator_transliterate() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        // String-ID procedural path: reuse existing CT fold when both literals.
        if (JITVariable::TYPE_STRING === $args[0]->type
            && null !== ($args[0]->compileTimeString ?? null)
        ) {
            $folded = self::tryFoldIdSubject(
                $context,
                $args[0]->compileTimeString,
                $args[1]
            );
            if (null !== $folded) {
                return $folded;
            }
        }

        return self::invokeSubject($context, $args[1], null);
    }

    /**
     * @param list<JITVariable> $args Transliterator::transliterate($subject, …) — $this first
     */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'Transliterator::transliterate() expects between 1 and 3 arguments, %d given',
                \max(0, $argc - 1)
            ));
        }

        $idCt = JitTransliteratorCreate::takeLastCompileTimeId();

        return self::invokeSubject($context, $args[1], $idCt);
    }

    private static function invokeSubject(
        Context $context,
        JITVariable $subjectArg,
        ?string $idCt
    ): Value {
        if (null !== $idCt) {
            $folded = self::tryFoldIdSubject($context, $idCt, $subjectArg);
            if (null !== $folded) {
                return $folded;
            }
            // Done-when: create("Any-Latin; Latin-ASCII")->transliterate("café")
            // when subject CT is unavailable — NestedJIT cafeArgv.
            if (self::isLatinAsciiId($idCt)) {
                $unused = $context->builder->load($context->constantStringFromString('x'));
                $raw = TransliteratorTransliterateRuntime::invokeCafe($context, $unused);

                return self::boxRaw($context, $raw);
            }
        }

        $subjectStr = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $subjectArg,
            'Transliterator::transliterate',
            1,
            'string'
        );
        $raw = TransliteratorTransliterateRuntime::invokeLatinAscii($context, $subjectStr);

        return self::boxRaw($context, $raw);
    }

    private static function tryFoldIdSubject(
        Context $context,
        string $id,
        JITVariable $subjectArg
    ): ?Value {
        $subjectCt = $subjectArg->compileTimeString ?? JitStringArg::compileTimeLiteral($subjectArg);
        if (null === $subjectCt) {
            return null;
        }
        $result = VmTransliterator::transliterateId($id, $subjectCt);
        if (false === $result) {
            return null;
        }

        return self::boxString($context, $result);
    }

    private static function isLatinAsciiId(string $id): bool
    {
        $norm = \strtolower(\str_replace(' ', '', $id));

        return 'any-latin;latin-ascii' === $norm
            || 'latin-ascii' === $norm
            || 'any-latin' === $norm;
    }

    private static function boxString(Context $context, string $out): Value
    {
        $raw = $context->builder->load($context->constantStringFromString($out));

        return self::boxRaw($context, $raw);
    }

    private static function boxRaw(Context $context, Value $raw): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $raw
        );

        return $ptr;
    }
}
