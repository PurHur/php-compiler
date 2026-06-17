<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmInfo;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM __compiler_phpversion / __compiler_php_sapi_name / __compiler_php_uname /
 * __compiler_extension_loaded / __compiler_get_loaded_extensions / __compiler_get_extension_funcs
 * (issue #6124, #3433).
 *
 * Mirrors ext/standard/VmInfo.php; replaces lib/AOT/runtime/phpc_info.c introspection.
 * php-src: ext/standard/info.c
 */
final class StringInfo
{
    private static int $blockSuffix = 0;
    /** Linux x86_64 glibc struct utsname (five 65-byte fields). */
    private const UTSNAME_SIZE = 325;

    private const UTSNAME_OFF_SYSNAME = 0;

    private const UTSNAME_OFF_NODENAME = 65;

    private const UTSNAME_OFF_RELEASE = 130;

    private const UTSNAME_OFF_VERSION = 195;

    private const UTSNAME_OFF_MACHINE = 260;

    private const UTSNAME_FIELD_LEN = 65;

    private const UNAME_RESULT_BUF = 512;

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

        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $fnExtLoaded = self::declareIfMissing(
            $context,
            '__compiler_extension_loaded',
            $context->context->functionType($i32, false, $strPtr)
        );
        self::implementExtensionLoaded($context, $fnExtLoaded);

        $fnPhpversion = self::declareIfMissing(
            $context,
            '__compiler_phpversion',
            $context->context->functionType($strPtr, false, $strPtr)
        );
        self::implementPhpversion($context, $fnPhpversion);

        $fnSapi = self::declareIfMissing(
            $context,
            '__compiler_php_sapi_name',
            $context->context->functionType($strPtr, false)
        );
        self::implementPhpSapiName($context, $fnSapi);

        $fnZendVersion = self::declareIfMissing(
            $context,
            '__compiler_zend_version',
            $context->context->functionType($strPtr, false)
        );
        self::implementZendVersion($context, $fnZendVersion);

        $fnUname = self::declareIfMissing(
            $context,
            '__compiler_php_uname',
            $context->context->functionType($strPtr, false, $strPtr)
        );
        self::implementPhpUname($context, $fnUname);

        $fnPosixUname = self::declareIfMissing(
            $context,
            '__compiler_posix_uname',
            $context->context->functionType($htPtr, false)
        );
        self::implementPosixUname($context, $fnPosixUname);

        $fnLoadedExt = self::declareIfMissing(
            $context,
            '__compiler_get_loaded_extensions',
            $context->context->functionType($htPtr, false, $i32)
        );
        self::implementGetLoadedExtensions($context, $fnLoadedExt);

        $fnExtFuncs = self::declareIfMissing(
            $context,
            '__compiler_get_extension_funcs',
            $context->context->functionType($htPtr, false, $strPtr)
        );
        self::implementGetExtensionFuncs($context, $fnExtFuncs);

        self::registerLinkedRuntime($context);
    }

    private static function implementPhpversion(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pv_entry');
        $context->builder->positionAtEnd($entry);

        $extension = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullExt = $context->builder->icmp(Builder::INT_EQ, $extension, $strPtr->constNull());
        $emptyExt = $context->builder->icmp(
            Builder::INT_EQ,
            self::stringLen($context, $extension),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $noExt = $context->builder->or($nullExt, $emptyExt);

        $versionBb = $fn->appendBasicBlock('pv_version');
        $checkBb = $fn->appendBasicBlock('pv_check');
        $failBb = $fn->appendBasicBlock('pv_fail');
        $context->builder->branchIf($noExt, $versionBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $isCore = self::stringEqualsIgnoreCase($context, $fn, $extension, 'core');
        $loaded = $context->builder->call(
            $context->lookupFunction('__compiler_extension_loaded'),
            $extension
        );
        $i32 = $context->getTypeFromString('int32');
        $isLoaded = $context->builder->icmp(Builder::INT_NE, $loaded, $i32->constInt(0, false));
        $hasVersion = $context->builder->or($isCore, $isLoaded);
        $context->builder->branchIf($hasVersion, $versionBb, $failBb);

        $context->builder->positionAtEnd($versionBb);
        $context->builder->returnValue(self::literalString($context, CompilerVersion::VERSION));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
        $context->builder->clearInsertionPosition();
    }

    private static function implementPhpSapiName(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('psn_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(self::literalString($context, CompilerVersion::SAPI));
        $context->builder->clearInsertionPosition();
    }

    private static function implementZendVersion(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('zv_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(self::literalString($context, VmInfo::ZEND_VERSION));
        $context->builder->clearInsertionPosition();
    }

    private static function implementPhpUname(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pu_entry');
        $context->builder->positionAtEnd($entry);

        $mode = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        $modeChar = self::unameModeChar($context, $mode);
        $bodyBb = $fn->appendBasicBlock('pu_body');
        $context->builder->branch($bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $uts = $context->builder->alloca($i8, self::UTSNAME_SIZE, 'pu_uts');
        $utsPtr = $context->builder->pointerCast($uts, $i8p);
        $status = $context->builder->call($context->lookupFunction('uname'), $utsPtr);
        $ok = $context->builder->icmp(Builder::INT_EQ, $status, $i32->constInt(0, false));
        $failBb = $fn->appendBasicBlock('pu_fail');
        $pickBb = $fn->appendBasicBlock('pu_pick');
        $context->builder->branchIf($ok, $pickBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue(self::literalString($context, ''));

        $context->builder->positionAtEnd($pickBb);
        $fieldPtr = self::selectUnameField($context, $fn, $uts, $modeChar);
        $isAll = self::modeIsAll($context, $modeChar);
        $singleBb = $fn->appendBasicBlock('pu_single');
        $allBb = $fn->appendBasicBlock('pu_all');
        $doneBb = $fn->appendBasicBlock('pu_done');
        $context->builder->branchIf($isAll, $allBb, $singleBb);

        $context->builder->positionAtEnd($singleBb);
        $single = self::cstringToString($context, $fieldPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($allBb);
        $buf = $context->builder->alloca($i8, self::UNAME_RESULT_BUF, 'pu_buf');
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%s %s %s %s %s'),
            $charPtr
        );
        $sys = self::fieldCstr($context, $uts, self::UTSNAME_OFF_SYSNAME);
        $node = self::fieldCstr($context, $uts, self::UTSNAME_OFF_NODENAME);
        $rel = self::fieldCstr($context, $uts, self::UTSNAME_OFF_RELEASE);
        $ver = self::fieldCstr($context, $uts, self::UTSNAME_OFF_VERSION);
        $mach = self::fieldCstr($context, $uts, self::UTSNAME_OFF_MACHINE);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $sizeT->constInt(self::UNAME_RESULT_BUF, false),
            $fmt,
            $sys,
            $node,
            $rel,
            $ver,
            $mach
        );
        $allStr = self::cstringToString($context, $bufChar);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($single, $singleBb);
        $phi->addIncoming($allStr, $allBb);
        $context->builder->returnValue($phi);
        $context->builder->clearInsertionPosition();
    }

    private static function implementPosixUname(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pun_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtr->constNull();

        $uts = $context->builder->alloca($i8, self::UTSNAME_SIZE, 'pun_uts');
        $utsPtr = $context->builder->pointerCast($uts, $i8p);
        $status = $context->builder->call($context->lookupFunction('uname'), $utsPtr);
        $ok = $context->builder->icmp(Builder::INT_EQ, $status, $i32->constInt(0, false));
        $failBb = $fn->appendBasicBlock('pun_fail');
        $fillBb = $fn->appendBasicBlock('pun_fill');
        $context->builder->branchIf($ok, $fillBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($fillBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        foreach ([
            ['sysname', self::UTSNAME_OFF_SYSNAME],
            ['nodename', self::UTSNAME_OFF_NODENAME],
            ['release', self::UTSNAME_OFF_RELEASE],
            ['version', self::UTSNAME_OFF_VERSION],
            ['machine', self::UTSNAME_OFF_MACHINE],
        ] as [$key, $offset]) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyString'),
                $ht,
                self::literalString($context, $key),
                self::cstringToString($context, self::fieldCstr($context, $uts, $offset))
            );
        }
        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    private static function implementExtensionLoaded(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('el_entry');
        $context->builder->positionAtEnd($entry);

        $name = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullName = $context->builder->icmp(Builder::INT_EQ, $name, $strPtr->constNull());
        $missBb = $fn->appendBasicBlock('el_miss');
        $checkBb = $fn->appendBasicBlock('el_check');
        $context->builder->branchIf($nullName, $missBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $emptyName = $context->builder->icmp(Builder::INT_EQ, self::stringLen($context, $name), $i64->constInt(0, false));
        $tooLong = $context->builder->icmp(
            Builder::INT_UGE,
            self::stringLen($context, $name),
            $i64->constInt(64, false)
        );
        $invalid = $context->builder->or($emptyName, $tooLong);
        $matchBb = $fn->appendBasicBlock('el_match');
        $context->builder->branchIf($invalid, $missBb, $matchBb);

        $context->builder->positionAtEnd($matchBb);
        $result = $i32->constInt(0, false);
        foreach (ModuleRegistry::getLoadedExtensions() as $literal) {
            $matches = self::stringEqualsIgnoreCase($context, $fn, $name, $literal);
            $result = $context->builder->select(
                $matches,
                $i32->constInt(1, false),
                $result
            );
        }
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->builder->clearInsertionPosition();
    }

    private static function implementGetLoadedExtensions(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gle_entry');
        $context->builder->positionAtEnd($entry);

        $zendExtensions = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $isZend = $context->builder->icmp(Builder::INT_NE, $zendExtensions, $i32->constInt(0, false));
        $emptyBb = $fn->appendBasicBlock('gle_empty');
        $fillBb = $fn->appendBasicBlock('gle_fill');
        $context->builder->branchIf($isZend, $emptyBb, $fillBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($fillBb);
        $i64 = $context->getTypeFromString('int64');
        foreach (ModuleRegistry::getLoadedExtensions() as $index => $literal) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringAt'),
                $ht,
                $context->builder->zExt($i64->constInt($index, false), $context->getTypeFromString('size_t')),
                self::literalString($context, $literal)
            );
        }
        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();
    }

    private static function implementGetExtensionFuncs(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gef_entry');
        $context->builder->positionAtEnd($entry);

        $name = $fn->getParam(0);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullName = $context->builder->icmp(Builder::INT_EQ, $name, $strPtr->constNull());
        $missBb = $fn->appendBasicBlock('gef_miss');
        $checkBb = $fn->appendBasicBlock('gef_check');
        $context->builder->branchIf($nullName, $missBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $i64 = $context->getTypeFromString('int64');
        $emptyName = $context->builder->icmp(Builder::INT_EQ, self::stringLen($context, $name), $i64->constInt(0, false));
        $tooLong = $context->builder->icmp(
            Builder::INT_UGE,
            self::stringLen($context, $name),
            $i64->constInt(64, false)
        );
        $invalid = $context->builder->or($emptyName, $tooLong);
        $matchBb = $fn->appendBasicBlock('gef_match');
        $context->builder->branchIf($invalid, $missBb, $matchBb);

        $context->builder->positionAtEnd($matchBb);
        $map = ModuleRegistry::extensionFunctionMap();
        if ([] === $map) {
            $context->builder->branch($missBb);
        } else {
            $extensions = array_keys($map);
            $count = \count($extensions);
            $tryBlocks = [];
            for ($i = 0; $i < $count; ++$i) {
                $tryBlocks[] = $fn->appendBasicBlock('gef_try_'.$i);
            }
            $context->builder->branch($tryBlocks[0]);
            for ($i = 0; $i < $count; ++$i) {
                $extension = $extensions[$i];
                $tryBb = $tryBlocks[$i];
                $context->builder->positionAtEnd($tryBb);
                $matches = self::stringEqualsIgnoreCase($context, $fn, $name, $extension);
                $fillBb = $fn->appendBasicBlock('gef_fill_'.$i);
                $nextBb = $i + 1 < $count
                    ? $tryBlocks[$i + 1]
                    : $missBb;
                $context->builder->branchIf($matches, $fillBb, $nextBb);

                $context->builder->positionAtEnd($fillBb);
                $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
                foreach ($map[$extension] as $index => $literal) {
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setStringAt'),
                        $ht,
                        $context->builder->zExt(
                            $i64->constInt($index, false),
                            $context->getTypeFromString('size_t')
                        ),
                        self::literalString($context, $literal)
                    );
                }
                $context->builder->returnValue($ht);
            }
        }

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($htPtr->constNull());
        $context->builder->clearInsertionPosition();
    }

    private static function stringEqualsIgnoreCase(
        Context $context,
        LlvmFunction $fn,
        Value $name,
        string $literal
    ): Value {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $litLen = \strlen($literal);
        $nameLen = self::stringLen($context, $name);
        $lenOk = $context->builder->icmp(Builder::INT_EQ, $nameLen, $i64->constInt($litLen, false));
        $failBb = $fn->appendBasicBlock('el_cmp_fail_'.(++self::$blockSuffix));
        $bodyBb = $fn->appendBasicBlock('el_cmp_body_'.self::$blockSuffix);
        $doneBb = $fn->appendBasicBlock('el_cmp_done_'.self::$blockSuffix);
        $context->builder->branchIf($lenOk, $bodyBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $map = self::stringFieldMap($context);
        $nameData = $context->builder->structGep($name, $map['value']);
        $match = $context->getTypeFromString('int1')->constInt(1, false);
        for ($i = 0; $i < $litLen; ++$i) {
            $na = $context->builder->load($context->builder->gep($nameData, $i32->constInt($i, false)));
            $nb = $i8->constInt(ord($literal[$i]), false);
            $naLower = self::asciiLower($context, $na);
            $nbLower = self::asciiLower($context, $nb);
            $same = $context->builder->icmp(Builder::INT_EQ, $naLower, $nbLower);
            $match = $context->builder->and($match, $same);
        }
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($context->getTypeFromString('int1'));
        $phi->addIncoming($falseVal, $failBb);
        $phi->addIncoming($match, $bodyBb);

        return $phi;
    }

    private static function asciiLower(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(90, false))
        );

        return $context->builder->select(
            $isUpper,
            $context->builder->add($ch, $i8->constInt(32, false)),
            $ch
        );
    }

    private static function unameModeChar(Context $context, Value $mode): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        $nullMode = $context->builder->icmp(Builder::INT_EQ, $mode, $strPtr->constNull());
        $default = $i8->constInt(ord('a'), false);
        $emptyMode = $context->builder->icmp(
            Builder::INT_EQ,
            self::stringLen($context, $mode),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $map = self::stringFieldMap($context);
        $nameData = $context->builder->structGep($mode, $map['value']);
        $first = $context->builder->load($nameData);

        return $context->builder->select(
            $context->builder->or($nullMode, $emptyMode),
            $default,
            $first
        );
    }

    private static function modeIsAll(Context $context, Value $modeChar): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $isSingle = $context->getTypeFromString('int1')->constInt(0, false);
        foreach (['s', 'n', 'r', 'v', 'm'] as $letter) {
            $isSingle = $context->builder->or(
                $isSingle,
                $context->builder->icmp(Builder::INT_EQ, $modeChar, $i8->constInt(ord($letter), false))
            );
        }

        return $context->builder->not($isSingle);
    }

    private static function selectUnameField(Context $context, LlvmFunction $fn, Value $uts, Value $modeChar): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $default = self::fieldCstr($context, $uts, self::UTSNAME_OFF_SYSNAME);
        $result = $default;
        foreach ([
            ['s', self::UTSNAME_OFF_SYSNAME],
            ['n', self::UTSNAME_OFF_NODENAME],
            ['r', self::UTSNAME_OFF_RELEASE],
            ['v', self::UTSNAME_OFF_VERSION],
            ['m', self::UTSNAME_OFF_MACHINE],
        ] as [$letter, $offset]) {
            $matches = $context->builder->icmp(
                Builder::INT_EQ,
                $modeChar,
                $i8->constInt(ord($letter), false)
            );
            $result = $context->builder->select(
                $matches,
                self::fieldCstr($context, $uts, $offset),
                $result
            );
        }

        return $result;
    }

    private static function fieldCstr(Context $context, Value $uts, int $offset): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->gep(
            $context->builder->pointerCast($uts, $i8p),
            $i8->constInt($offset, false)
        );
    }

    private static function cstringToString(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
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

    private static function stringLen(Context $context, Value $str): Value
    {
        $map = self::stringFieldMap($context);

        return $context->builder->load($context->builder->structGep($str, $map['length']));
    }

    /** @return array{ref: int, length: int, value: int} */
    private static function stringFieldMap(Context $context): array
    {
        return $context->structFieldMap['__string__'] ?? ['ref' => 0, 'length' => 1, 'value' => 2];
    }

    private static function declareIfMissing(Context $context, string $name, $ft): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            $context->registerFunction($name, $probe);

            return $probe;
        }

        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach ([
            ['uname', $i32, [$i8p]],
            ['strlen', $sizeT, [$i8p]],
            ['snprintf', $i32, [$charPtr, $sizeT, $charPtr], true],
        ] as $spec) {
            $variadic = $spec[3] ?? false;
            self::ensureExternal(
                $context,
                $spec[0],
                $context->context->functionType($spec[1], $variadic, ...$spec[2])
            );
        }
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringInfo LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
