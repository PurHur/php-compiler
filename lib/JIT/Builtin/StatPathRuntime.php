<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for file predicates + stat fields via PHP helpers (#9112).
 *
 * Replaces glibc struct stat layout LLVM in {@see \PHPCompiler\ext\standard\JitStat}.
 * php-src: ext/standard/filestat.c
 */
final class StatPathRuntime
{
    private const PATH_HELPER_PATH = '/ext/standard/StatPathJitHelper.php';

    private const FIELDS_HELPER_PATH = '/ext/standard/StatFieldsJitHelper.php';

    public const FN_EXISTS = '__phpc_jit_path_exists';

    public const FN_IS_FILE = '__phpc_jit_path_is_file';

    public const FN_IS_DIR = '__phpc_jit_path_is_dir';

    public const FN_IS_LINK = '__phpc_jit_path_is_link';

    public const FN_IS_READABLE = '__phpc_jit_path_is_readable';

    public const FN_IS_WRITABLE = '__phpc_jit_path_is_writable';

    public const FN_IS_EXECUTABLE = '__phpc_jit_path_is_executable';

    public const FN_LONG_FIELD = '__phpc_jit_stat_long_field';

    public const FN_FILETYPE_LABEL = '__phpc_jit_filetype_label';

    public const FN_DISK_FREE = '__phpc_jit_disk_free_bytes';

    public const FN_DISK_TOTAL = '__phpc_jit_disk_total_bytes';

    private const EXISTS_HELPER = 'PHPCompiler\\ext\\standard\\StatPathJitHelper::exists';

    private const IS_FILE_HELPER = 'PHPCompiler\\ext\\standard\\StatPathJitHelper::isFile';

    private const IS_DIR_HELPER = 'PHPCompiler\\ext\\standard\\StatPathJitHelper::isDir';

    private const IS_LINK_HELPER = 'PHPCompiler\\ext\\standard\\StatPathJitHelper::isLink';

    private const IS_READABLE_HELPER = 'PHPCompiler\\ext\\standard\\StatPathJitHelper::isReadable';

    private const IS_WRITABLE_HELPER = 'PHPCompiler\\ext\\standard\\StatPathJitHelper::isWritable';

    private const IS_EXECUTABLE_HELPER = 'PHPCompiler\\ext\\standard\\StatPathJitHelper::isExecutable';

    private const LONG_FIELD_HELPER = 'PHPCompiler\\ext\\standard\\StatFieldsJitHelper::longField';

    private const FILETYPE_LABEL_HELPER = 'PHPCompiler\\ext\\standard\\StatFieldsJitHelper::filetypeLabel';

    private const DISK_FREE_HELPER = 'PHPCompiler\\ext\\standard\\StatFieldsJitHelper::diskFreeBytes';

    private const DISK_TOTAL_HELPER = 'PHPCompiler\\ext\\standard\\StatFieldsJitHelper::diskTotalBytes';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXISTS_HELPER,
        self::IS_FILE_HELPER,
        self::IS_DIR_HELPER,
        self::IS_LINK_HELPER,
        self::IS_READABLE_HELPER,
        self::IS_WRITABLE_HELPER,
        self::IS_EXECUTABLE_HELPER,
        self::LONG_FIELD_HELPER,
        self::FILETYPE_LABEL_HELPER,
        self::DISK_FREE_HELPER,
        self::DISK_TOTAL_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        self::FN_EXISTS,
        self::FN_IS_FILE,
        self::FN_IS_DIR,
        self::FN_IS_LINK,
        self::FN_IS_READABLE,
        self::FN_IS_WRITABLE,
        self::FN_IS_EXECUTABLE,
        self::FN_LONG_FIELD,
        self::FN_FILETYPE_LABEL,
        self::FN_DISK_FREE,
        self::FN_DISK_TOTAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::FN_EXISTS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelpersCompiled($context);
        self::implementPathBoolBridge($context, self::FN_EXISTS, self::EXISTS_HELPER);
        self::implementPathBoolBridge($context, self::FN_IS_FILE, self::IS_FILE_HELPER);
        self::implementPathBoolBridge($context, self::FN_IS_DIR, self::IS_DIR_HELPER);
        self::implementPathBoolBridge($context, self::FN_IS_LINK, self::IS_LINK_HELPER);
        self::implementPathBoolBridge($context, self::FN_IS_READABLE, self::IS_READABLE_HELPER);
        self::implementPathBoolBridge($context, self::FN_IS_WRITABLE, self::IS_WRITABLE_HELPER);
        self::implementPathBoolBridge($context, self::FN_IS_EXECUTABLE, self::IS_EXECUTABLE_HELPER);
        self::implementLongFieldBridge($context);
        self::implementFiletypeBridge($context);
        self::implementDiskBridge($context, self::FN_DISK_FREE, self::DISK_FREE_HELPER);
        self::implementDiskBridge($context, self::FN_DISK_TOTAL, self::DISK_TOTAL_HELPER);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementPathBoolBridge(Context $context, string $abiName, string $helper): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('stat_path_bool_entry');
        $fail = $fn->appendBasicBlock('stat_path_bool_null');
        $run = $fn->appendBasicBlock('stat_path_bool_run');
        $context->builder->positionAtEnd($entry);
        $path = $fn->getParam(0);
        $nullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($nullPath, $fail, $run);

        $context->builder->positionAtEnd($run);
        $hit = $context->builder->call(self::helperFunction($context, $helper), $path);
        $context->builder->returnValue($hit);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i1->constInt(0, false));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementLongFieldBridge(Context $context): void
    {
        $abiName = self::FN_LONG_FIELD;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $strPtr, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('stat_long_field_entry');
        $fail = $fn->appendBasicBlock('stat_long_field_null');
        $run = $fn->appendBasicBlock('stat_long_field_run');
        $context->builder->positionAtEnd($entry);
        $path = $fn->getParam(0);
        $nullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($nullPath, $fail, $run);

        $context->builder->positionAtEnd($run);
        $value = $context->builder->call(
            self::helperFunction($context, self::LONG_FIELD_HELPER),
            $path,
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($value);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementFiletypeBridge(Context $context): void
    {
        $abiName = self::FN_FILETYPE_LABEL;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('filetype_label_entry');
        $fail = $fn->appendBasicBlock('filetype_label_null');
        $run = $fn->appendBasicBlock('filetype_label_run');
        $context->builder->positionAtEnd($entry);
        $path = $fn->getParam(0);
        $nullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($nullPath, $fail, $run);

        $context->builder->positionAtEnd($run);
        $label = $context->builder->call(
            self::helperFunction($context, self::FILETYPE_LABEL_HELPER),
            $path
        );
        $context->builder->returnValue($label);

        $context->builder->positionAtEnd($fail);
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $context->builder->returnValue($empty);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementDiskBridge(Context $context, string $abiName, string $helper): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('disk_bytes_entry');
        $fail = $fn->appendBasicBlock('disk_bytes_null');
        $run = $fn->appendBasicBlock('disk_bytes_run');
        $context->builder->positionAtEnd($entry);
        $path = $fn->getParam(0);
        $nullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($nullPath, $fail, $run);

        $context->builder->positionAtEnd($run);
        $bytes = $context->builder->call(self::helperFunction($context, $helper), $path);
        $context->builder->returnValue($bytes);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelpersCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StatPathJitHelper compile (#9112)');
        }

        return $fn;
    }

    private static function ensureJitHelpersCompiled(Context $context): void
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

        StatCacheRuntime::ensureLinked($context);
        $runtime = $context->runtime;
        $root = \dirname(__DIR__, 3);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $root): void {
            foreach ([self::PATH_HELPER_PATH, self::FIELDS_HELPER_PATH] as $rel) {
                $path = $root.$rel;
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), \basename($path));
                if (null === $block) {
                    throw new \LogicException(\basename($path).' parseAndCompile failed (#9112)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
            }
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9112)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StatPathRuntime bridge (#9112)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
