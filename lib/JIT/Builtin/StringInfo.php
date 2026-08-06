<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for __compiler_phpversion / __compiler_extension_loaded / … via InfoJitHelper PHP (#9148, #13803).
 *
 * Thin {@see JitVmHelperLink} glue; standalone php_sapi_name uses a compile-time constant (#15633).
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmInfo}.
 * php-src: ext/standard/info.c
 */
final class StringInfo
{
    private const HELPER_PATH = '/ext/standard/InfoJitHelper.php';

    private const PHPVERSION_ARGV_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::phpversionArgv';

    private const ZEND_VERSION_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::zend_version';

    private const PHP_UNAME_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::php_uname';

    private const PHP_UNAME_STRICT_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::php_unameStrict';

    private const EXTENSION_LOADED_ARGV_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::extensionLoadedArgv';

    private const GET_LOADED_EXTENSIONS_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::getLoadedExtensionsArgv';

    private const GET_EXTENSION_FUNCS_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::getExtensionFuncsArgv';

    private const POSIX_UNAME_AVAILABLE_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::posixUnameAvailable';

    private const POSIX_UNAME_FIELD_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::posixUnameField';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PHPVERSION_ARGV_HELPER,
        self::ZEND_VERSION_HELPER,
        self::PHP_UNAME_HELPER,
        self::PHP_UNAME_STRICT_HELPER,
        self::EXTENSION_LOADED_ARGV_HELPER,
        self::GET_LOADED_EXTENSIONS_HELPER,
        self::GET_EXTENSION_FUNCS_HELPER,
        self::POSIX_UNAME_AVAILABLE_HELPER,
        self::POSIX_UNAME_FIELD_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_phpversion',
        '__compiler_php_sapi_name',
        '__compiler_zend_version',
        '__compiler_php_uname',
        '__compiler_php_uname_strict',
        '__compiler_posix_uname',
        '__compiler_extension_loaded',
        '__compiler_get_loaded_extensions',
        '__compiler_get_extension_funcs',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Lazy link for php_sapi_name() only — avoids nested InfoJitHelper compile on standalone AOT (#15633). */
    public static function ensurePhpSapiNameLinked(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::implementPhpSapiNameConstantBridge($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
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

        self::ensureHashtableHelpers($context);
        self::implementPhpSapiNameConstantBridge($context);
        self::ensureNullableStringBridge(
            $context,
            '__compiler_phpversion',
            'info_phpversion_entry',
            [$strPtr],
            self::PHPVERSION_ARGV_HELPER
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
            '__compiler_php_uname_strict',
            'info_php_uname_strict_entry',
            [$strPtr],
            $strPtr,
            self::PHP_UNAME_STRICT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28136'
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
        self::implementPosixUnameBridge($context);
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

    private static function implementPhpSapiNameConstantBridge(Context $context): void
    {
        $abiName = '__compiler_php_sapi_name';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureHashtableHelpers($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false)
            );

        $entry = $fn->appendBasicBlock('info_php_sapi_name_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(self::literalString($context, CompilerVersion::SAPI));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementPosixUnameBridge(Context $context): void
    {
        $abiName = '__compiler_posix_uname';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#15633');
        $availableFn = JitVmHelperLink::lookupCompiled($context, self::POSIX_UNAME_AVAILABLE_HELPER, '#15633');
        $fieldFn = JitVmHelperLink::lookupCompiled($context, self::POSIX_UNAME_FIELD_HELPER, '#15633');

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($htPtr, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('info_pun_entry');
        $failBb = $fn->appendBasicBlock('info_pun_fail');
        $fillBb = $fn->appendBasicBlock('info_pun_fill');
        $loopHead = $fn->appendBasicBlock('info_pun_loop_head');
        $loopBody = $fn->appendBasicBlock('info_pun_loop_body');
        $loopDone = $fn->appendBasicBlock('info_pun_loop_done');

        $context->builder->positionAtEnd($entry);
        $available = $context->builder->call($availableFn);
        $availableI32 = $available->typeOf() === $i32
            ? $available
            : $context->builder->truncOrBitCast($available, $i32);
        $ok = $context->builder->icmp(Builder::INT_NE, $availableI32, $i32->constInt(0, false));
        $context->builder->branchIf($ok, $fillBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($fillBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $iSlot = $context->builder->alloca($i64, 1, 'info_pun_i');
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $done = $context->builder->icmp(Builder::INT_SGE, $i, $i64->constInt(6, false));
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $value = $context->builder->call($fieldFn, $i);
        $key = self::unameKeyForIndex($context, $i);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $key,
            $value
        );
        $context->builder->store(
            $context->builder->add($i, $i64->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function unameKeyForIndex(Context $context, Value $index): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $key = self::literalString($context, 'sysname');
        foreach ([1 => 'nodename', 2 => 'release', 3 => 'version', 4 => 'machine', 5 => 'domainname'] as $idx => $literal) {
            $matches = $context->builder->icmp(Builder::INT_EQ, $index, $i64->constInt($idx, false));
            $key = $context->builder->select($matches, self::literalString($context, $literal), $key);
        }

        return $key;
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
            ['__string__init', $strPtr, [$i64, $charPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
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
                throw new \LogicException($name.' missing after StringInfo bridge (#13803)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
