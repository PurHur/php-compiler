<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmInfo;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT LLVM __compiler_phpinfo / __compiler_phpcredits until VmInfo HTML compiles in native link (#9256).
 *
 * JIT path uses {@see StringPhpinfoRuntime} + {@see \PHPCompiler\ext\standard\PhpinfoJitHelper}.
 * php-src: ext/standard/info.c
 */
final class StringPhpinfoRuntimeLlvm
{
    private static int $blockSuffix = 0;

    public static function implement(Context $context): void
    {
        ObOutput::registerExternals($context);
        ObOutputRuntime::ensureLinked($context);
        StringInfo::ensureLinked($context);
        self::ensureObEchoSubstr($context);

        $i32 = $context->getTypeFromString('int32');
        $fnPhpinfo = self::declareIfMissing(
            $context,
            '__compiler_phpinfo',
            $context->context->functionType($i32, false, $i32)
        );
        self::implementPhpinfo($context, $fnPhpinfo);

        $voidTy = $context->getTypeFromString('void');
        $fnCredits = self::declareIfMissing(
            $context,
            '__compiler_phpcredits',
            $context->context->functionType($voidTy, false, $i32)
        );
        self::implementPhpcredits($context, $fnCredits);
    }

    private static function implementPhpinfo(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pinfo_entry');
        $done = $fn->appendBasicBlock('pinfo_done');
        $context->builder->positionAtEnd($entry);

        $flags = $fn->getParam(0);
        self::emitPhpinfoHtmlHeader($context);

        self::emitSectionIfSelected($context, $flags, VmInfo::INFO_GENERAL, self::emitGeneralSection(...));
        self::emitSectionIfSelected($context, $flags, VmInfo::INFO_MODULES, self::emitModulesSection(...));
        self::emitSectionIfSelected($context, $flags, VmInfo::INFO_CONFIGURATION, self::emitConfigurationSection(...));
        self::emitSectionIfSelected($context, $flags, VmInfo::INFO_LICENSE, self::emitLicenseSection(...));
        self::emitSectionIfSelected($context, $flags, VmInfo::INFO_CREDITS, static function (Context $ctx): void {
            self::emitCreditsSection($ctx, VmInfo::CREDITS_GENERAL);
        });

        self::emitObEchoCstr($context, '</div></body></html>');
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->returnValue($i32->constInt(1, false));
        $context->builder->clearInsertionPosition();
    }

    private static function implementPhpcredits(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pcred_entry');
        $skip = $fn->appendBasicBlock('pcred_skip');
        $work = $fn->appendBasicBlock('pcred_work');
        $done = $fn->appendBasicBlock('pcred_done');
        $context->builder->positionAtEnd($entry);

        $flags = $fn->getParam(0);
        $selected = self::emitCreditsFlagSelected($context, $flags, VmInfo::CREDITS_GENERAL);
        $context->builder->branchIf($selected, $work, $skip);

        $context->builder->positionAtEnd($work);
        self::emitCreditsSection($context, VmInfo::CREDITS_GENERAL);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param callable(Context): void $emit
     */
    private static function emitSectionIfSelected(Context $context, Value $flags, int $section, callable $emit): void
    {
        $fn = BasicBlockHelper::parentFunction($context);
        $selected = self::emitInfoFlagSelected($context, $flags, $section);
        $work = $fn->appendBasicBlock('pinfo_sec_'.(++self::$blockSuffix));
        $skip = $fn->appendBasicBlock('pinfo_skip_'.self::$blockSuffix);
        $context->builder->branchIf($selected, $work, $skip);
        $context->builder->positionAtEnd($work);
        $emit($context);
        $context->builder->branch($skip);
        $context->builder->positionAtEnd($skip);
    }

    private static function emitPhpinfoHtmlHeader(Context $context): void
    {
        self::emitObEchoCstr($context, '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "DTD/xhtml1-transitional.dtd">');
        self::emitObEchoCstr($context, '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" />');
        self::emitObEchoCstr($context, '<title>phpinfo()</title><style type="text/css">.h{background-color:#9999cc;font-weight:bold;color:#000000;}');
        self::emitObEchoCstr($context, '.v{background-color:#cccccc;color:#000000;}</style></head><body><div class="center">');
    }

    private static function emitGeneralSection(Context $context): void
    {
        $version = CompilerVersion::VERSION;
        self::emitObEchoCstr($context, '<table><tr class="h"><td colspan="2"><h1>PHP Version ');
        self::emitObEchoCompilerString($context, self::callCompilerPhpversion($context, null));
        self::emitObEchoCstr($context, '</h1></td></tr>');
        self::emitObEchoCstr($context, '<tr><td class="e">System </td><td class="v">');
        foreach (['s', 'n', 'r', 'v', 'm'] as $mode) {
            self::emitObEchoCompilerString($context, self::callCompilerPhpUname($context, $mode));
            self::emitObEchoCstr($context, ' ');
        }
        self::emitObEchoCstr($context, '</td></tr>');
        self::emitObEchoCstr($context, '<tr><td class="e">Build System </td><td class="v">');
        self::emitObEchoCompilerString($context, self::callCompilerPhpUname($context, 's'));
        self::emitObEchoCstr($context, ' ');
        self::emitObEchoCompilerString($context, self::callCompilerPhpUname($context, 'm'));
        self::emitObEchoCstr($context, ' </td></tr>');
        self::emitObEchoCstr($context, '<tr><td class="e">Server API </td><td class="v">');
        self::emitObEchoCompilerString($context, self::callCompilerPhpSapiName($context));
        self::emitObEchoCstr($context, ' </td></tr>');
        self::emitObEchoCstr($context, '<tr><td class="e">PHP Version </td><td class="v">'.$version.' </td></tr>');
        self::emitObEchoCstr($context, '<tr><td class="e">Zend Engine Version </td><td class="v">'.VmInfo::ZEND_VERSION.' </td></tr>');
        self::emitObEchoCstr($context, '</table><br />');
    }

    private static function emitModulesSection(Context $context): void
    {
        $extensions = ModuleRegistry::getLoadedExtensions();
        sort($extensions, SORT_STRING);
        self::emitObEchoCstr($context, '<table><tr class="h"><td colspan="2"><h2>PHP Modules</h2></td></tr>');
        self::emitObEchoCstr($context, '<tr><td class="e">Module Name </td><td class="v">Enabled </td></tr>');
        foreach ($extensions as $name) {
            self::emitObEchoCstr(
                $context,
                '<tr><td class="e">'.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' </td><td class="v">enabled </td></tr>'
            );
        }
        self::emitObEchoCstr($context, '</table><br />');
    }

    private static function emitConfigurationSection(Context $context): void
    {
        self::emitObEchoCstr($context, '<table><tr class="h"><td colspan="2"><h2>Configuration</h2></td></tr>');
        self::emitObEchoCstr($context, '<tr><td class="e">Compiler </td><td class="v">PurHur/php-compiler </td></tr>');
        self::emitObEchoCstr($context, '</table><br />');
    }

    private static function emitLicenseSection(Context $context): void
    {
        self::emitObEchoCstr($context, '<table><tr class="h"><td colspan="2"><h2>PHP License</h2></td></tr>');
        self::emitObEchoCstr($context, '<tr><td class="v" colspan="2">This program is free software; you can redistribute it and/or modify it under the terms of the PHP License.</td></tr>');
        self::emitObEchoCstr($context, '</table><br />');
    }

    private static function emitCreditsSection(Context $context, int $flags): void
    {
        unset($flags);
        self::emitObEchoCstr($context, '<table><tr class="h"><td colspan="2"><h2>PHP Credits</h2></td></tr>');
        self::emitObEchoCstr($context, '<tr><td class="v" colspan="2">PurHur/php-compiler — PHP-in-PHP compiler runtime</td></tr>');
        self::emitObEchoCstr($context, '</table><br />');
    }

    private static function callCompilerPhpversion(Context $context, ?string $extension): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $extArg = $strPtr->constNull();
        if (null !== $extension) {
            $extArg = self::literalString($context, $extension);
        }

        return $context->builder->call(
            $context->lookupFunction('__compiler_phpversion'),
            $extArg
        );
    }

    private static function callCompilerPhpSapiName(Context $context): Value
    {
        return $context->builder->call($context->lookupFunction('__compiler_php_sapi_name'));
    }

    private static function callCompilerPhpUname(Context $context, string $mode): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__compiler_php_uname'),
            self::literalString($context, $mode)
        );
    }

    private static function emitInfoFlagSelected(Context $context, Value $flags, int $section): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $all = $context->builder->icmp(Builder::INT_EQ, $flags, $i32->constInt(-1, true));
        $masked = $context->builder->and($flags, $i32->constInt($section, false));
        $hasBit = $context->builder->icmp(Builder::INT_NE, $masked, $i32->constInt(0, false));

        return $context->builder->or($all, $hasBit);
    }

    private static function emitCreditsFlagSelected(Context $context, Value $flags, int $section): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $all = $context->builder->icmp(Builder::INT_EQ, $flags, $i32->constInt(-1, true));
        $masked = $context->builder->and($flags, $i32->constInt($section, false));
        $hasBit = $context->builder->icmp(Builder::INT_NE, $masked, $i32->constInt(0, false));

        return $context->builder->or($all, $hasBit);
    }

    private static function emitObEchoCstr(Context $context, string $text): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_cstr'),
            $context->builder->pointerCast($context->constantFromString($text), $i8p)
        );
    }

    private static function emitObEchoCompilerString(Context $context, Value $str): void
    {
        $map = $context->structFieldMap['__string__'] ?? ['ref' => 0, 'length' => 1, 'value' => 2];
        $len = $context->builder->load($context->builder->structGep($str, $map['length']));
        $data = $context->builder->structGep($str, $map['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $data,
            $context->builder->zExt($len, $sizeT)
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

    private static function ensureObEchoSubstr(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        self::ensureExternal(
            $context,
            '__phpc_ob_echo_substr',
            $context->context->functionType($voidTy, false, $i8p, $sizeT)
        );
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType(
                $context->getTypeFromString('__string__*'),
                false,
                $context->getTypeFromString('int64'),
                $context->getTypeFromString('char*')
            )
        );
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

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
