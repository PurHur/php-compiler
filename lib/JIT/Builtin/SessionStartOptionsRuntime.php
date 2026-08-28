<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\ext\standard\JitIni;

/**
 * JIT/AOT apply session_start($options) before {@see __phpc_session_start_apply} (#33945).
 *
 * String-keyed array literals: emit option stores from {@see JitVariable::$compileTimeAssoc}
 * at the call site (boxed INIT_ARRAY HT is empty at runtime — peer filter_var_array #34574).
 *
 * php-src: ext/session/session.c — PHP_FUNCTION(session_start)
 */
final class SessionStartOptionsRuntime
{
    /** Retained for TypeDeadSessionStartOptionsAbiRuntimeShrinkTest source scan. */
    public const ABI = '__phpc_session_start_options_apply';

    private const HELPER_PATH = '/ext/standard/SessionStartOptionsAotJitHelper.php';

    private const APPLY_OPTIONS_HELPER = 'PHPCompiler\\ext\\standard\\SessionStartOptionsAotJitHelper::applyOptions';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::APPLY_OPTIONS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        StringTriggerError::ensureLinked($context);

        // NestedJIT scalar coerce looks up __compiler_trigger_error (#33248 / #33945).
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#33945',
            true
        );
    }

    public static function applyOptionsAtCallSite(Context $context, JitVariable $options): void
    {
        if (\is_array($options->compileTimeAssoc) && [] !== $options->compileTimeAssoc) {
            self::emitCompileTimeOptions($context, $options->compileTimeAssoc);

            return;
        }

        self::ensureLinked($context);
        $optionsHt = HashTableReadLlvm::loadHashtablePointer($context, $options);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::APPLY_OPTIONS_HELPER, '#33945');
        JitNestedHelperCoerce::callHelper(
            $context,
            $helperFn,
            [$optionsHt]
        );
    }

    /**
     * @param array<string|int, mixed> $assoc
     */
    private static function emitCompileTimeOptions(Context $context, array $assoc): void
    {
        SessionName::ensureLinked($context);
        foreach ($assoc as $key => $val) {
            if (!\is_string($key)) {
                continue;
            }
            if ('name' === $key) {
                if (!\is_string($val)) {
                    continue;
                }
                self::emitSetName($context, $val);
                continue;
            }
            if (!self::isSupportedCompileTimeScalar($val)) {
                continue;
            }
            $iniVal = self::compileTimeScalarToIniString($val);
            JitIni::set(
                $context,
                $context->builder->load($context->constantStringFromString('session.'.$key)),
                $context->builder->load($context->constantStringFromString($iniVal))
            );
        }
    }

    private static function emitSetName(Context $context, string $name): void
    {
        $i8 = $context->getTypeFromString('int8');
        $nullStr = $context->getTypeFromString('__string__*')->constNull();
        $nullBoxed = $context->getTypeFromString('__value__*')->constNull();
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_session_name_apply'),
            $i8->constInt(SessionName::APPLY_SET, false),
            $context->builder->load($context->constantStringFromString($name)),
            $nullBoxed,
            $ptr
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'sso_ct_name_done');
    }

    private static function isSupportedCompileTimeScalar(mixed $val): bool
    {
        return \is_string($val) || \is_int($val) || \is_bool($val);
    }

    private static function compileTimeScalarToIniString(mixed $val): string
    {
        if (\is_bool($val)) {
            return $val ? '1' : '0';
        }
        if (\is_int($val)) {
            return (string) $val;
        }

        return (string) $val;
    }

    /** @param array<string|int, mixed> $php */
    private static function phpAssocToHashTable(array $php): HashTable
    {
        $ht = new HashTable();
        foreach ($php as $key => $val) {
            $cell = self::phpScalarToVariable($val);
            if (\is_int($key) || (\is_string($key) && ctype_digit($key) && (string) (int) $key === $key)) {
                $ht->addIndex((int) $key, $cell);
            } else {
                $ht->add((string) $key, $cell);
            }
        }

        return $ht;
    }

    private static function phpScalarToVariable(mixed $val): VmVariable
    {
        $out = new VmVariable();
        if (null === $val) {
            $out->null();
        } elseif (\is_bool($val)) {
            $out->bool($val);
        } elseif (\is_int($val)) {
            $out->int($val);
        } elseif (\is_float($val)) {
            $out->float($val);
        } elseif (\is_string($val)) {
            $out->string($val);
        } else {
            $out->null();
        }

        return $out;
    }
}
