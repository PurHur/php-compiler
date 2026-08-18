<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT ABI bridges for upload temp helpers via UploadTempJitHelper PHP (#5346, #9799, #19723, #23301).
 *
 * Quarantined from lib/JIT/Builtin/UploadTempJit — {@see \PHPCompiler\JIT\Builtin\UploadTempJit}
 * stays the thin orchestrator. Call-site lowering stays in {@see JitIsUploadedFile}/{@see JitMoveUploadedFile}.
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer EnvLocal #23211 / StatPath #23297).
 *
 * Replaces ~520-line LLVM path validation; SSOT {@see VmFs}.
 * php-src: ext/standard/basic_functions.c — is_uploaded_file, move_uploaded_file
 */
final class JitUploadTempKernel
{
    private const HELPER_PATH = '/ext/standard/UploadTempJitHelper.php';

    private const TRAVERSAL_HELPER = 'PHPCompiler\\ext\\standard\\UploadTempJitHelper::pathHasParentTraversal';

    private const TEMP_DIR_HELPER = 'PHPCompiler\\ext\\standard\\UploadTempJitHelper::tempDir';

    private const VALID_TEMP_HELPER = 'PHPCompiler\\ext\\standard\\UploadTempJitHelper::isValidTemp';

    private const IS_UPLOADED_HELPER = 'PHPCompiler\\ext\\standard\\UploadTempJitHelper::isUploadedFile';

    private const MOVE_UPLOADED_HELPER = 'PHPCompiler\\ext\\standard\\UploadTempJitHelper::moveUploadedFile';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TRAVERSAL_HELPER,
        self::TEMP_DIR_HELPER,
        self::VALID_TEMP_HELPER,
        self::IS_UPLOADED_HELPER,
        self::MOVE_UPLOADED_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__phpc_upload_path_has_traversal',
        '__phpc_upload_tmpdir_name',
        '__phpc_upload_is_valid_temp',
        '__compiler_is_uploaded_file',
        '__compiler_move_uploaded_file',
    ];

    public static function implement(Context $context): void
    {
        // Restore caller insert block — clearInsertionPosition mid-user-compile
        // leaves parentless __compiler_is_uploaded_file calls (#28853, peer #21109).
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        $probe = $context->module->getNamedFunction('__compiler_is_uploaded_file');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $saved);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementPathHasTraversalBridge($context);
        self::implementTempDirBridge($context);
        self::implementIsValidTempBridge($context);
        self::implementIsUploadedFileBridge($context);
        self::implementMoveUploadedFileBridge($context);
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $saved);
    }

    private static function implementPathHasTraversalBridge(Context $context): void
    {
        $abiName = '__phpc_upload_path_has_traversal';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i32, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('upload_traversal_entry');
        $context->builder->positionAtEnd($entry);
        $path = $fn->getParam(0);
        $pathStr = self::cstrToString($context, $path);
        $result = $context->builder->call(
            self::helperFunction($context, self::TRAVERSAL_HELPER),
            $pathStr
        );
        $context->builder->returnValue($context->builder->trunc($result, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementTempDirBridge(Context $context): void
    {
        $abiName = '__phpc_upload_tmpdir_name';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($i8p, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('upload_tmpdir_entry');
        $context->builder->positionAtEnd($entry);
        $dirStr = $context->builder->call(self::helperFunction($context, self::TEMP_DIR_HELPER));
        $map = $context->structFieldMap['__string__'];
        $context->builder->returnValue($context->builder->structGep($dirStr, $map['value']));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementIsValidTempBridge(Context $context): void
    {
        $abiName = '__phpc_upload_is_valid_temp';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i32, false, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('upload_valid_entry');
        $context->builder->positionAtEnd($entry);
        $pathStr = self::cstrToString($context, $fn->getParam(0));
        $result = $context->builder->call(
            self::helperFunction($context, self::VALID_TEMP_HELPER),
            $pathStr
        );
        $context->builder->returnValue($context->builder->trunc($result, $i32));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementIsUploadedFileBridge(Context $context): void
    {
        $abiName = '__compiler_is_uploaded_file';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($i32, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('is_uploaded_entry');
        $failBb = $fn->appendBasicBlock('is_uploaded_fail');
        $workBb = $fn->appendBasicBlock('is_uploaded_work');
        $context->builder->positionAtEnd($entry);
        $pathObj = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $pathObj, $strPtr->constNull());
        $context->builder->branchIf($isNull, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $pathStr = self::stringObjectToString($context, $pathObj);
        $result = $context->builder->call(
            self::helperFunction($context, self::IS_UPLOADED_HELPER),
            $pathStr
        );
        $context->builder->returnValue($context->builder->trunc($result, $i32));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementMoveUploadedFileBridge(Context $context): void
    {
        $abiName = '__compiler_move_uploaded_file';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($i32, false, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('move_uploaded_entry');
        $failBb = $fn->appendBasicBlock('move_uploaded_fail');
        $workBb = $fn->appendBasicBlock('move_uploaded_work');
        $context->builder->positionAtEnd($entry);
        $fromObj = $fn->getParam(0);
        $toObj = $fn->getParam(1);
        $eitherNull = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $fromObj, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $toObj, $strPtr->constNull())
        );
        $context->builder->branchIf($eitherNull, $failBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $fromStr = self::stringObjectToString($context, $fromObj);
        $toStr = self::stringObjectToString($context, $toObj);
        $result = $context->builder->call(
            self::helperFunction($context, self::MOVE_UPLOADED_HELPER),
            $fromStr,
            $toStr
        );
        $context->builder->returnValue($context->builder->trunc($result, $i32));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->registerFunction($abiName, $fn);
    }

    private static function stringObjectToString(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $data = $context->builder->structGep($strObj, $map['value']);
        $len = $context->builder->load($context->builder->structGep($strObj, $map['length']));

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($data, $charPtr)
        );
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $i8p = $context->getTypeFromString('int8*');
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23301');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        self::ensureStringInit($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23301'
        );
    }

    private static function ensureStringInit(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $strPtr = $context->getTypeFromString('__string__*');
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $charPtr)
        );
        self::ensureExternal(
            $context,
            'strlen',
            $context->context->functionType($context->getTypeFromString('size_t'), false, $context->getTypeFromString('int8*'))
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
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after JitUploadTempKernel bridge (#9799/#19723)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
