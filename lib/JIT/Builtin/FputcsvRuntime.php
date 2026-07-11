<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for fputcsv() field formatting via CsvJitHelper PHP (#12447).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmFputcsv} + {@see \PHPCompiler\ext\standard\VmCsv::formatLine}
 * php-src: ext/standard/file.c — php_fputcsv()
 */
final class FputcsvRuntime
{
    private const ABI_FORMAT = '__fputcsv__format_fields';

    private const HELPER_PATH = '/ext/standard/CsvJitHelper.php';

    private const FORMAT_HELPER = 'PHPCompiler\\ext\\standard\\CsvJitHelper::formatFieldsArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_HELPER,
    ];

    public static function formatFields(
        Context $context,
        Value $fieldsHt,
        Value $separator,
        Value $enclosure,
        Value $escape,
    ): Value {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            throw new \LogicException('fputcsv() scalar field JIT requires linked CsvJitHelper (#12447)');
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FORMAT),
            $fieldsHt,
            $separator,
            $enclosure,
            $escape
        );
    }

    public static function ensureLinked(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_FORMAT);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinked($context);

            return;
        }

        $savedBlock = self::saveInsertBlock($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FORMAT,
            'fputcsv_format_fields_bridge_entry',
            [$htPtr, $strPtr, $strPtr, $strPtr],
            $strPtr,
            self::FORMAT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#12447'
        );
        self::registerLinked($context);
        self::restoreInsertBlock($context, $savedBlock);
    }

    private static function registerLinked(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_FORMAT);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_FORMAT.' missing after FputcsvRuntime bridge (#12447)');
        }
        $context->registerFunction(self::ABI_FORMAT, $fn);
    }

    private static function saveInsertBlock(Context $context): mixed
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, mixed $savedBlock): void
    {
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
