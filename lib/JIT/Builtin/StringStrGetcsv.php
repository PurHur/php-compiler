<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_str_getcsv via CsvJitHelper PHP (#9444).
 *
 * JIT/normal modules use compiled {@see CsvJitHelper}; AOT standalone keeps
 * {@see StringStrGetcsvJit} until native link can host compiled helpers reliably.
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_getcsv)
 */
final class StringStrGetcsv
{
    private const HELPER_PATH = '/ext/standard/CsvJitHelper.php';

    private const VM_CSV_PATH = '/ext/standard/VmCsv.php';

    private const STR_GETCSV_HELPER = 'PHPCompiler\\ext\\standard\\CsvJitHelper::strGetcsvArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STR_GETCSV_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_str_getcsv',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StringStrGetcsvJit::implement($context);

            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_str_getcsv');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureRuntimeHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementStrGetcsvBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementStrGetcsvBridge(Context $context): void
    {
        $abiName = '__compiler_str_getcsv';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($htPtr, false, $strPtr, $strPtr, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('str_getcsv_bridge_entry');
        $nullBb = $fn->appendBasicBlock('str_getcsv_bridge_null');
        $bodyBb = $fn->appendBasicBlock('str_getcsv_bridge_body');
        $context->builder->positionAtEnd($entry);

        $input = $fn->getParam(0);
        $separator = $fn->getParam(1);
        $enclosure = $fn->getParam(2);
        $escape = $fn->getParam(3);

        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $input, $strPtr->constNull()),
            $nullBb,
            $bodyBb
        );

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($bodyBb);
        $inputSep = $context->builder->call($context->lookupFunction('__string__separate'), $input);
        $sepSep = self::coerceOptionalCsvString($context, $separator, ',');
        $encSep = self::coerceOptionalCsvString($context, $enclosure, '"');
        $escSep = self::coerceOptionalCsvString($context, $escape, '\\');
        $ht = $context->builder->call(
            self::helperFunction($context, self::STR_GETCSV_HELPER),
            $inputSep,
            $sepSep,
            $encSep,
            $escSep
        );
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    public static function coerceOptionalCsvStringForFgetcsv(Context $context, Value $arg, string $default): Value
    {
        return self::coerceOptionalCsvString($context, $arg, $default);
    }

    private static function coerceOptionalCsvString(Context $context, Value $arg, string $default): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $nullBb = $fn->appendBasicBlock('csv_opt_str_null');
        $checkBb = $fn->appendBasicBlock('csv_opt_str_check');
        $useBb = $fn->appendBasicBlock('csv_opt_str_use');
        $doneBb = $fn->appendBasicBlock('csv_opt_str_done');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $arg, $strPtr->constNull());
        $context->builder->branchIf($isNull, $nullBb, $checkBb);

        $context->builder->positionAtEnd($nullBb);
        $defaultSep = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->constantFromString($default)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkBb);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($arg, $map['length']));
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $context->builder->branchIf($empty, $nullBb, $useBb);

        $context->builder->positionAtEnd($useBb);
        $separated = $context->builder->call($context->lookupFunction('__string__separate'), $arg);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr, [
            [$defaultSep, $nullBb],
            [$separated, $useBb],
        ]);

        return $phi;
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after CsvJitHelper compile (#9444)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $root = \dirname(__DIR__, 3);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $root): void {
            $jit = new JIT($context);
            foreach ([self::VM_CSV_PATH, self::HELPER_PATH] as $relative) {
                $path = $root.$relative;
                $real = \realpath($path) ?: $path;
                if ($context->hasJitIncludedFileCompiled($real)) {
                    continue;
                }
                $block = $runtime->parseAndCompile(
                    (string) \file_get_contents($path),
                    \basename($path)
                );
                if (null === $block) {
                    throw new \LogicException(\basename($path).' parseAndCompile failed (#9444)');
                }
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($real);
            }
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9444)');
            }
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        foreach (
            [
                ['__string__separate', $strPtr, [$strPtr]],
            ] as [$name, $ret, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringStrGetcsv bridge (#9444)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
