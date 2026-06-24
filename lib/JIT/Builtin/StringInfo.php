<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_phpversion / __compiler_extension_loaded / … via InfoJitHelper PHP (#9148).
 *
 * Replaces ~700-line LLVM info introspection. VM SSOT: {@see \PHPCompiler\ext\standard\VmInfo}.
 * php-src: ext/standard/info.c
 */
final class StringInfo
{
    private const HELPER_PATH = '/ext/standard/InfoJitHelper.php';

    private const PHPVERSION_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::phpversion';

    private const PHP_SAPI_NAME_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::php_sapi_name';

    private const ZEND_VERSION_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::zend_version';

    private const PHP_UNAME_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::php_uname';

    private const EXTENSION_LOADED_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::extension_loaded';

    private const COUNT_LOADED_EXTENSIONS_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::countLoadedExtensions';

    private const LOADED_EXTENSION_AT_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::loadedExtensionAt';

    private const PREPARE_GET_EXTENSION_FUNCS_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::prepareGetExtensionFuncs';

    private const EXTENSION_FUNC_AT_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::extensionFuncAt';

    private const POSIX_UNAME_AVAILABLE_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::posixUnameAvailable';

    private const POSIX_UNAME_FIELD_HELPER = 'PHPCompiler\\ext\\standard\\InfoJitHelper::posixUnameField';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PHPVERSION_HELPER,
        self::PHP_SAPI_NAME_HELPER,
        self::ZEND_VERSION_HELPER,
        self::PHP_UNAME_HELPER,
        self::EXTENSION_LOADED_HELPER,
        self::COUNT_LOADED_EXTENSIONS_HELPER,
        self::LOADED_EXTENSION_AT_HELPER,
        self::PREPARE_GET_EXTENSION_FUNCS_HELPER,
        self::EXTENSION_FUNC_AT_HELPER,
        self::POSIX_UNAME_AVAILABLE_HELPER,
        self::POSIX_UNAME_FIELD_HELPER,
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

        self::ensureHashtableHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementPhpversionBridge($context);
        self::implementPhpSapiNameBridge($context);
        self::implementZendVersionBridge($context);
        self::implementPhpUnameBridge($context);
        self::implementExtensionLoadedBridge($context);
        self::implementGetLoadedExtensionsBridge($context);
        self::implementGetExtensionFuncsBridge($context);
        self::implementPosixUnameBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementPhpversionBridge(Context $context): void
    {
        $abiName = '__compiler_phpversion';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('info_phpversion_entry');
        $failBb = $fn->appendBasicBlock('info_phpversion_fail');
        $okBb = $fn->appendBasicBlock('info_phpversion_ok');

        $context->builder->positionAtEnd($entry);
        $versionRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::PHPVERSION_HELPER),
            [$fn->getParam(0)]
        );
        $versionStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $versionRaw);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($versionStr, $map['length']));
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $context->builder->branchIf($empty, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($versionStr);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementPhpSapiNameBridge(Context $context): void
    {
        self::implementStringReturnBridge($context, '__compiler_php_sapi_name', self::PHP_SAPI_NAME_HELPER);
    }

    private static function implementZendVersionBridge(Context $context): void
    {
        self::implementStringReturnBridge($context, '__compiler_zend_version', self::ZEND_VERSION_HELPER);
    }

    private static function implementPhpUnameBridge(Context $context): void
    {
        $abiName = '__compiler_php_uname';
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

        $entry = $fn->appendBasicBlock('info_php_uname_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::PHP_UNAME_HELPER),
            [$fn->getParam(0)]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementExtensionLoadedBridge(Context $context): void
    {
        $abiName = '__compiler_extension_loaded';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i32, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('info_ext_loaded_entry');
        $missBb = $fn->appendBasicBlock('info_ext_loaded_miss');
        $workBb = $fn->appendBasicBlock('info_ext_loaded_work');
        $callBb = $fn->appendBasicBlock('info_ext_loaded_call');

        $context->builder->positionAtEnd($entry);
        $name = $fn->getParam(0);
        $nullName = $context->builder->icmp(Builder::INT_EQ, $name, $strPtr->constNull());
        $context->builder->branchIf($nullName, $missBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($name, $map['length']));
        $emptyName = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $context->builder->branchIf($emptyName, $missBb, $callBb);

        $context->builder->positionAtEnd($callBb);
        $loadedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::EXTENSION_LOADED_HELPER),
            [$name]
        );
        $loadedI32 = JitNestedHelperCoerce::coerceHelperScalarResult($context, $loadedRaw, $i32);
        $context->builder->returnValue($loadedI32);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->registerFunction($abiName, $fn);
    }

    private static function implementGetLoadedExtensionsBridge(Context $context): void
    {
        $abiName = '__compiler_get_loaded_extensions';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($htPtr, false, $i32);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('info_gle_entry');
        $bodyBb = $fn->appendBasicBlock('info_gle_body');
        $loopHead = $fn->appendBasicBlock('info_gle_loop_head');
        $loopBody = $fn->appendBasicBlock('info_gle_loop_body');
        $loopDone = $fn->appendBasicBlock('info_gle_loop_done');

        $context->builder->positionAtEnd($entry);
        $zendExtensions = $fn->getParam(0);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->branch($bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $countRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::COUNT_LOADED_EXTENSIONS_HELPER),
            [$zendExtensions]
        );
        $countI64 = JitNestedHelperCoerce::coerceHelperScalarResult($context, $countRaw, $i64);
        $iSlot = $context->builder->alloca($i64, 1, 'info_gle_i');
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $done = $context->builder->icmp(Builder::INT_SGE, $i, $countI64);
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $literalRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LOADED_EXTENSION_AT_HELPER),
            [$i]
        );
        $literal = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $literalRaw);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $context->builder->zext($i, $sizeT),
            $literal
        );
        $context->builder->store(
            $context->builder->add($i, $i64->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementGetExtensionFuncsBridge(Context $context): void
    {
        $abiName = '__compiler_get_extension_funcs';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($htPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('info_gef_entry');
        $missBb = $fn->appendBasicBlock('info_gef_miss');
        $workBb = $fn->appendBasicBlock('info_gef_work');
        $failBb = $fn->appendBasicBlock('info_gef_fail');
        $fillBb = $fn->appendBasicBlock('info_gef_fill');
        $loopHead = $fn->appendBasicBlock('info_gef_loop_head');
        $loopBody = $fn->appendBasicBlock('info_gef_loop_body');
        $loopDone = $fn->appendBasicBlock('info_gef_loop_done');

        $context->builder->positionAtEnd($entry);
        $name = $fn->getParam(0);
        $nullName = $context->builder->icmp(Builder::INT_EQ, $name, $strPtr->constNull());
        $context->builder->branchIf($nullName, $missBb, $workBb);

        $context->builder->positionAtEnd($workBb);
        $count = $context->builder->call(
            self::helperFunction($context, self::PREPARE_GET_EXTENSION_FUNCS_HELPER),
            $name
        );
        $countI64 = $count->typeOf() === $i64
            ? $count
            : $context->builder->sext($count, $i64);
        $zero = $context->builder->icmp(Builder::INT_EQ, $countI64, $i64->constInt(0, false));
        $context->builder->branchIf($zero, $failBb, $fillBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($fillBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $iSlot = $context->builder->alloca($i64, 1, 'info_gef_i');
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $done = $context->builder->icmp(Builder::INT_SGE, $i, $countI64);
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $literal = $context->builder->call(
            self::helperFunction($context, self::EXTENSION_FUNC_AT_HELPER),
            $i
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $context->builder->zext($i, $sizeT),
            $literal
        );
        $context->builder->store(
            $context->builder->add($i, $i64->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($htPtr->constNull());
        $context->registerFunction($abiName, $fn);
    }

    private static function implementPosixUnameBridge(Context $context): void
    {
        $abiName = '__compiler_posix_uname';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

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
        $available = $context->builder->call(self::helperFunction($context, self::POSIX_UNAME_AVAILABLE_HELPER));
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
        $done = $context->builder->icmp(Builder::INT_SGE, $i, $i64->constInt(5, false));
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $value = $context->builder->call(
            self::helperFunction($context, self::POSIX_UNAME_FIELD_HELPER),
            $i
        );
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
    }

    private static function unameKeyForIndex(Context $context, Value $index): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $key = self::literalString($context, 'sysname');
        foreach ([1 => 'nodename', 2 => 'release', 3 => 'version', 4 => 'machine'] as $idx => $literal) {
            $matches = $context->builder->icmp(Builder::INT_EQ, $index, $i64->constInt($idx, false));
            $key = $context->builder->select($matches, self::literalString($context, $literal), $key);
        }

        return $key;
    }

    private static function implementStringReturnBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('info_str_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            []
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
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

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after InfoJitHelper compile (#9148)');
        }

        return $fn;
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringAt', $voidTy, [$htPtr, $sizeT, $strPtr]],
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
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $realPath = \realpath($path) ?: $path;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $realPath): void {
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'InfoJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('InfoJitHelper.php parseAndCompile failed (#9148)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($realPath);
            });
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9148)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StringInfo bridge (#9148)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
