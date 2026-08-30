<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT compile link for ZipArchiveJitHelper (#35424 leftover of #6414).
 *
 * Single NestedJIT string-returning {@see exec} — packs LE int32 + optional payload.
 */
final class ZipArchiveEmbedBridge
{
    private const HELPER_PATH = '/ext/zip/ZipArchiveJitHelper.php';

    private const EXEC = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::exec';

    private const ADD_ENTRY = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::addEntry';

    private const ADD_EMPTY_DIR = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::addEmptyDirEntry';

    private const REPLACE_ENTRY = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::replaceEntry';

    private const SET_ARCHIVE_COMMENT = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::setArchiveCommentEntry';

    private const GET_ARCHIVE_COMMENT = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::getArchiveCommentEntry';

    private const SET_COMMENT_NAME = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::setCommentNameEntry';

    private const GET_COMMENT_NAME = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::getCommentNameEntry';

    private const SET_COMMENT_INDEX = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::setCommentIndexEntry';

    private const GET_COMMENT_INDEX = 'PHPCompiler\\ext\\zip\\ZipArchiveJitHelper::getCommentIndexEntry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXEC,
        self::ADD_ENTRY,
        self::ADD_EMPTY_DIR,
        self::REPLACE_ENTRY,
        self::SET_ARCHIVE_COMMENT,
        self::GET_ARCHIVE_COMMENT,
        self::SET_COMMENT_NAME,
        self::GET_COMMENT_NAME,
        self::SET_COMMENT_INDEX,
        self::GET_COMMENT_INDEX,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function execHelper(): string
    {
        return self::EXEC;
    }

    public static function addEntryHelper(): string
    {
        return self::ADD_ENTRY;
    }

    public static function addEmptyDirHelper(): string
    {
        return self::ADD_EMPTY_DIR;
    }

    public static function replaceEntryHelper(): string
    {
        return self::REPLACE_ENTRY;
    }

    public static function setArchiveCommentHelper(): string
    {
        return self::SET_ARCHIVE_COMMENT;
    }

    public static function getArchiveCommentHelper(): string
    {
        return self::GET_ARCHIVE_COMMENT;
    }

    public static function setCommentNameHelper(): string
    {
        return self::SET_COMMENT_NAME;
    }

    public static function getCommentNameHelper(): string
    {
        return self::GET_COMMENT_NAME;
    }

    public static function setCommentIndexHelper(): string
    {
        return self::SET_COMMENT_INDEX;
    }

    public static function getCommentIndexHelper(): string
    {
        return self::GET_COMMENT_INDEX;
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#35424');
    }

    public static function opString(Context $context, string $op): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $bytes = $context->builder->pointerCast(
            $context->constantFromString($op),
            $context->getTypeFromString('char*')
        );

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(strlen($op), false),
            $bytes
        );
    }

    public static function emptyString(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $i64->constInt(0, false)
        );
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        // NestedJIT ag/ap lower glob/scandir/file_get_contents/preg_match (#35537).
        // Peer RegexIteratorFilterRuntime (#26825) / StringFsGlobVecJit (#29986).
        StringFsGlobVecJit::implement($context);
        StringFileGetContents::ensureLinked($context);
        StringPregMatch::ensureLinked($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#35424'
        );
        // NestedJIT may declare ABI symbols after first ensureLinked — re-bind (#26825 peer).
        StringFsGlobVecJit::implement($context);
        StringFileGetContents::ensureLinked($context);
        StringPregMatch::ensureLinked($context);
    }
}
