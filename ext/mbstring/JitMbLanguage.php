<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbLanguageRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_language() (#4636, #21538, #35259 runtime language).
 *
 * Compile-time fold validates via {@see MbstringLanguageRegistry}; runtime
 * canonicalize via NestedJIT {@see MbLanguageJitHelper}; mutable code in module
 * global (peer {@see JitMbInternalEncoding}).
 */
final class JitMbLanguage
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_language() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (0 === $argc
            || (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            return self::lowerGet($context);
        }

        $languageLit = JitStringArg::compileTimeLiteral($args[0]);
        $canonicalLit = null;
        if (null !== $languageLit) {
            $canonicalLit = MbstringLanguageRegistry::assertValid(
                $languageLit,
                'mb_language',
                0
            );
        }

        return self::lowerSet($context, $args[0], $canonicalLit);
    }

    private static function lowerGet(Context $context): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbLanguageRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_language_get');

        $g = MbLanguageRuntime::languageCodeGlobal($context);
        $code = $context->builder->load($g);
        $i64 = $context->getTypeFromString('int64');

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $doneBb = BasicBlockHelper::append($context, 'mb_language_get_done');

        $names = [
            MbLanguageJitHelper::CODE_NEUTRAL => 'neutral',
            MbLanguageJitHelper::CODE_UNI => 'uni',
            MbLanguageJitHelper::CODE_ENGLISH => 'English',
            MbLanguageJitHelper::CODE_GERMAN => 'German',
            MbLanguageJitHelper::CODE_JAPANESE => 'Japanese',
            MbLanguageJitHelper::CODE_KOREAN => 'Korean',
            MbLanguageJitHelper::CODE_RUSSIAN => 'Russian',
            MbLanguageJitHelper::CODE_SIMPLIFIED_CHINESE => 'Simplified Chinese',
            MbLanguageJitHelper::CODE_TRADITIONAL_CHINESE => 'Traditional Chinese',
            MbLanguageJitHelper::CODE_ARMENIAN => 'Armenian',
            MbLanguageJitHelper::CODE_UKRAINIAN => 'Ukrainian',
            MbLanguageJitHelper::CODE_TURKISH => 'Turkish',
        ];
        $next = null;
        foreach ($names as $codeVal => $name) {
            $matchBb = BasicBlockHelper::append($context, 'mb_language_get_'.$codeVal);
            $elseBb = BasicBlockHelper::append($context, 'mb_language_get_not_'.$codeVal);
            if (null !== $next) {
                $context->builder->positionAtEnd($next);
            }
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $code,
                $i64->constInt($codeVal, false)
            );
            $context->builder->branchIf($isMatch, $matchBb, $elseBb);

            $context->builder->positionAtEnd($matchBb);
            self::writeStringConstant($context, $ptr, $name);
            $context->builder->branch($doneBb);

            $next = $elseBb;
        }

        // 0 / unknown → neutral default.
        $context->builder->positionAtEnd($next);
        self::writeStringConstant($context, $ptr, 'neutral');
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    private static function lowerSet(Context $context, JITVariable $arg, ?string $canonicalLit): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbLanguageRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_language_set');

        $i64 = $context->getTypeFromString('int64');
        if (null !== $canonicalLit) {
            $code = $i64->constInt(self::codeForCanonical($canonicalLit), false);
        } else {
            $lang = JitStringBuiltinArg::lower(
                $context,
                $arg,
                'mb_language',
                0,
                'language'
            );
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                MbLanguageRuntime::canonicalizeHelper($context),
                [$lang]
            );
            $code = JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64);
        }

        $g = MbLanguageRuntime::languageCodeGlobal($context);
        $context->builder->store($code, $g);

        return $context->constantFromBool(true);
    }

    private static function writeStringConstant(Context $context, Value $valuePtr, string $name): void
    {
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($name))
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $valuePtr,
            $owned
        );
    }

    private static function codeForCanonical(string $canonical): int
    {
        return match ($canonical) {
            'neutral' => MbLanguageJitHelper::CODE_NEUTRAL,
            'uni' => MbLanguageJitHelper::CODE_UNI,
            'English' => MbLanguageJitHelper::CODE_ENGLISH,
            'German' => MbLanguageJitHelper::CODE_GERMAN,
            'Japanese' => MbLanguageJitHelper::CODE_JAPANESE,
            'Korean' => MbLanguageJitHelper::CODE_KOREAN,
            'Russian' => MbLanguageJitHelper::CODE_RUSSIAN,
            'Simplified Chinese' => MbLanguageJitHelper::CODE_SIMPLIFIED_CHINESE,
            'Traditional Chinese' => MbLanguageJitHelper::CODE_TRADITIONAL_CHINESE,
            'Armenian' => MbLanguageJitHelper::CODE_ARMENIAN,
            'Ukrainian' => MbLanguageJitHelper::CODE_UKRAINIAN,
            'Turkish' => MbLanguageJitHelper::CODE_TURKISH,
            default => MbLanguageJitHelper::CODE_NEUTRAL,
        };
    }
}
