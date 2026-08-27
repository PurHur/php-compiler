<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/** JIT/AOT link for ZipArchiveJitHelper (#35424). */
final class ZipArchiveEmbedBridge
{
    private const HELPER_PATH = '/ext/zip/ZipArchiveJitHelper.php';
    private const ALLOC = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::alloc';
    private const OPEN = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::open';
    private const CLOSE = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::close';
    private const ADD = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::addFromString';
    private const FOUND = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::getFromNameFound';
    private const DATA = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::getFromNameData';
    private const NUM = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::propNumFiles';
    private const STATUS = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::propStatus';
    private const STATUS_SYS = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::propStatusSys';
    private const LAST = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::propLastId';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ALLOC, self::OPEN, self::CLOSE, self::ADD, self::FOUND, self::DATA,
        self::NUM, self::STATUS, self::STATUS_SYS, self::LAST,
    ];

    public static function ensureLinked(Context $context): void
    {
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#35424');
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureLinked($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#35424');
    }

    public static function alloc(Context $context): LlvmFunction { return self::helperFunction($context, self::ALLOC); }
    public static function open(Context $context): LlvmFunction { return self::helperFunction($context, self::OPEN); }
    public static function close(Context $context): LlvmFunction { return self::helperFunction($context, self::CLOSE); }
    public static function addFromString(Context $context): LlvmFunction { return self::helperFunction($context, self::ADD); }
    public static function getFromNameFound(Context $context): LlvmFunction { return self::helperFunction($context, self::FOUND); }
    public static function getFromNameData(Context $context): LlvmFunction { return self::helperFunction($context, self::DATA); }
    public static function propNumFiles(Context $context): LlvmFunction { return self::helperFunction($context, self::NUM); }
    public static function propStatus(Context $context): LlvmFunction { return self::helperFunction($context, self::STATUS); }
    public static function propStatusSys(Context $context): LlvmFunction { return self::helperFunction($context, self::STATUS_SYS); }
    public static function propLastId(Context $context): LlvmFunction { return self::helperFunction($context, self::LAST); }
}
