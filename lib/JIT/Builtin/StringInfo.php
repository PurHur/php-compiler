<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for __compiler_phpversion / __compiler_extension_loaded / … via InfoJitHelper PHP (#9148, #13803).
 *
 * Thin {@see JitVmHelperLink} glue; hashtable fill loops live in {@see \PHPCompiler\ext\standard\InfoJitHelper}.
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmInfo}.
 * php-src: ext/standard/info.c
 */
final class StringInfo
{
    private const HELPER_PATH = '/ext/standard/InfoJitHelper.php';

    private const PHPVERSION_ARGV_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::phpversionArgv';

    private const PHP_SAPI_NAME_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::php_sapi_name';

    private const ZEND_VERSION_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::zend_version';

    private const PHP_UNAME_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::php_uname';

    private const EXTENSION_LOADED_ARGV_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::extensionLoadedArgv';

    private const GET_LOADED_EXTENSIONS_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::getLoadedExtensionsArgv';

    private const GET_EXTENSION_FUNCS_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::getExtensionFuncsArgv';

    private const POSIX_UNAME_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::posixUnameArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PHPVERSION_ARGV_HELPER,
        self::PHP_SAPI_NAME_HELPER,
        self::ZEND_VERSION_HELPER,
        self::PHP_UNAME_HELPER,
        self::EXTENSION_LOADED_ARGV_HELPER,
        self::GET_LOADED_EXTENSIONS_HELPER,
        self::GET_EXTENSION_FUNCS_HELPER,
        self::POSIX_UNAME_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_phpversion',
        '__compiler_php_sapi_name',
        '__compiler_zend_version',
        '__compiler_php_uname',
        '__compiler_posix_uname',
        '__compiler_extension_loaded',
        '__compiler_get_loaded_extensions',
        '__compiler_get_extension_funcs',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_php_sapi_name');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');

        self::ensureNullableStringBridge(
            $context,
            '__compiler_phpversion',
            'info_phpversion_entry',
            [$strPtr],
            self::PHPVERSION_ARGV_HELPER
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_php_sapi_name',
            'info_php_sapi_name_entry',
            [],
            $strPtr,
            self::PHP_SAPI_NAME_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13803'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_zend_version',
            'info_zend_version_entry',
            [],
            $strPtr,
            self::ZEND_VERSION_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13803'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_php_uname',
            'info_php_uname_entry',
            [$strPtr],
            $strPtr,
            self::PHP_UNAME_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13803'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_extension_loaded',
            'info_extension_loaded_entry',
            [$strPtr],
            $i32,
            self::EXTENSION_LOADED_ARGV_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13803'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_get_loaded_extensions',
            'info_get_loaded_extensions_entry',
            [$i32],
            $htPtr,
            self::GET_LOADED_EXTENSIONS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13803'
        );
        self::ensureNullableHashtableBridge(
            $context,
            '__compiler_get_extension_funcs',
            'info_get_extension_funcs_entry',
            [$strPtr],
            self::GET_EXTENSION_FUNCS_HELPER
        );
        self::ensureNullableHashtableBridge(
            $context,
            '__compiler_posix_uname',
            'info_posix_uname_entry',
            [],
            self::POSIX_UNAME_HELPER
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureNullableStringBridge(
        Context $context,
        string $abiName,
        string $entryBlockName,
        array $paramTypes,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#13803');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#13803');

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, ...$paramTypes)
            );

        $entry = $fn->appendBasicBlock($entryBlockName);
        $fail = $fn->appendBasicBlock($abiName.'_null');
        $ok = $fn->appendBasicBlock($abiName.'_ret');
        $context->builder->positionAtEnd($entry);

        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i)->typeOf()
            );
        }
        $raw = $context->builder->call($helperFn, ...$args);
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isNull, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($strPtr->constNull());
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureNullableHashtableBridge(
        Context $context,
        string $abiName,
        string $entryBlockName,
        array $paramTypes,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#13803');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#13803');

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($htPtr, false, ...$paramTypes)
            );

        $entry = $fn->appendBasicBlock($entryBlockName);
        $fail = $fn->appendBasicBlock($abiName.'_null');
        $ok = $fn->appendBasicBlock($abiName.'_ret');
        $context->builder->positionAtEnd($entry);

        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i)->typeOf()
            );
        }
        $raw = $context->builder->call($helperFn, ...$args);
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isNull, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $htPtr)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($htPtr->constNull());
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StringInfo bridge (#13803)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
