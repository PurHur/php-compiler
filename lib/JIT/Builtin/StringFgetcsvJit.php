<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_fgetcsv via CsvJitHelper PHP (#6750, #9444, #13440, #26135).
 *
 * Helper compile: bundled {@see JitVmHelperLink::ensureCompiledBundle}
 * (VmCsv → VmFs → CsvJitHelper) in one NestedJIT scope (peer StringStrGetcsv #26135 / Unpack #25830).
 * php-src: ext/standard/file.c — PHP_FUNCTION(fgetcsv)
 */
final class StringFgetcsvJit
{
    private const HELPER_PATH = '/ext/standard/CsvJitHelper.php';

    private const VM_FS_PATH = '/ext/standard/VmFs.php';

    private const VM_CSV_PATH = '/ext/standard/VmCsv.php';

    /**
     * Ordered NestedJIT sources — VmCsv/VmFs before helper (#26135).
     *
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        self::VM_CSV_PATH,
        self::VM_FS_PATH,
        self::HELPER_PATH,
    ];

    private const FGETCSV_HELPER = 'PHPCompiler\\ext\\standard\\CsvJitHelper::fgetcsvArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FGETCSV_HELPER,
        'PHPCompiler\\ext\\standard\\CsvJitHelper::parseLineArgv',
        'PHPCompiler\\ext\\standard\\CsvJitHelper::strGetcsvArgv',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_fgetcsv',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fgetcsv');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StringStrGetcsv::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementFgetcsvBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementFgetcsvBridge(Context $context): void
    {
        $abiName = '__compiler_fgetcsv';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($htPtr, false, $i64, $i64, $strPtr, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('fgetcsv_bridge_entry');
        $bodyBb = $fn->appendBasicBlock('fgetcsv_bridge_body');
        $context->builder->positionAtEnd($entry);
        $context->builder->branch($bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $handle = $fn->getParam(0);
        $length = $fn->getParam(1);
        $separator = $fn->getParam(2);
        $enclosure = $fn->getParam(3);
        $escape = $fn->getParam(4);
        $sepSep = StringStrGetcsv::coerceOptionalCsvStringForFgetcsv($context, $separator, ',');
        $encSep = StringStrGetcsv::coerceOptionalCsvStringForFgetcsv($context, $enclosure, '"');
        $escSep = StringStrGetcsv::coerceOptionalCsvStringForFgetcsv($context, $escape, '\\');
        $ht = $context->builder->call(
            self::helperFunction($context, self::FGETCSV_HELPER),
            $handle,
            $length,
            $sepSep,
            $encSep,
            $escSep
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $nullBb = $fn->appendBasicBlock('fgetcsv_bridge_null');
        $retBb = $fn->appendBasicBlock('fgetcsv_bridge_ret');
        $context->builder->branchIf($isNull, $nullBb, $retBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26135');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#26135'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFgetcsvJit bridge (#13440)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
