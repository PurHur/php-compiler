<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\BasicBlock;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for php_ini_loaded_file() / php_ini_scanned_files() via IniIntrospectionJitHelper PHP (#11562, #25630).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer PosixTimes #25600 / GetcwdJit #25541).
 * Replaces LLVM getenv lowering in {@see \PHPCompiler\ext\standard\JitIniIntrospection}.
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmIniIntrospection}
 * php-src: ext/standard/basic_functions.c — php_ini_loaded_file / php_ini_scanned_files
 */
final class IniIntrospectionRuntime
{
    private const HELPER_PATH = '/ext/standard/IniIntrospectionJitHelper.php';

    private const LOADED_FILE_HELPER = 'PHPCompiler\\ext\\standard\\IniIntrospectionJitHelper::loadedFile';

    private const SCANNED_FILES_HELPER = 'PHPCompiler\\ext\\standard\\IniIntrospectionJitHelper::scannedFiles';

    private const FN_LOADED_FILE = '__phpc_ini_introspection_loaded_file';

    private const FN_SCANNED_FILES = '__phpc_ini_introspection_scanned_files';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOADED_FILE_HELPER,
        self::SCANNED_FILES_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::FN_LOADED_FILE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $restoreBlock = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::ensureValueWriters($context);
        self::implementIfMissing($context, self::FN_LOADED_FILE, self::implementLoadedFileBridge(...));
        self::implementIfMissing($context, self::FN_SCANNED_FILES, self::implementScannedFilesBridge(...));
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restoreBlock);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareBridgeFunction($context, $name, $probe);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareBridgeFunction(Context $context, string $name, ?LlvmFunction $probe): LlvmFunction
    {
        if (null !== $probe) {
            return $probe;
        }

        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');

        return $context->module->addFunction(
            $name,
            $context->context->functionType($voidTy, false, $valPtr)
        );
    }

    private static function implementLoadedFileBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ini_loaded_file_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LOADED_FILE_HELPER),
            []
        );
        self::writeHelperStringOrFalseToValue($context, $fn->getParam(0), $result);
        $context->builder->returnVoid();
    }

    private static function implementScannedFilesBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ini_scanned_files_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::SCANNED_FILES_HELPER),
            []
        );
        self::writeHelperStringOrFalseToValue($context, $fn->getParam(0), $result);
        $context->builder->returnVoid();
    }

    private static function writeHelperStringOrFalseToValue(Context $context, Value $out, Value $raw): void
    {
        $i32 = $context->getTypeFromString('int32');
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $falseBb = BasicBlockHelper::append($context, 'ini_intro_result_false');
        $okBb = BasicBlockHelper::append($context, 'ini_intro_result_string');
        $doneBb = BasicBlockHelper::append($context, 'ini_intro_result_done');

        $context->builder->branchIf($isNull, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25630');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25630'
        );
    }

    private static function ensureValueWriters(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');

        self::ensureExternal(
            $context,
            '__value__writeString',
            $context->context->functionType($voidTy, false, $valPtr, $strPtr)
        );
        self::ensureExternal(
            $context,
            '__value__writeBool',
            $context->context->functionType($voidTy, false, $valPtr, $i32)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::FN_LOADED_FILE, self::FN_SCANNED_FILES] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after IniIntrospectionRuntime bridge (#11562)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
