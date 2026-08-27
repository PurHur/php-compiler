<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\zip\JitZipArchive;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ZipArchive thin-AOT methods — open / add / close / get / locate / index / rename / delete /
 * extract / status / count / writable / archive comment / entry comment / unchangeAll
 * (#35424 / #35437 / #35440 / #35449 / #35450 / #35455 / #35465 / #35466 / #35467 / #35472 /
 * #35476 / #35478 / #35486 / #35490).
 *
 * php-src: ext/zip/php_zip.c
 */
final class ZipArchiveMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match (strtolower($this->method)) {
            'open' => JitZipArchive::open($context, ...$args),
            'addfromstring' => JitZipArchive::addFromString($context, ...$args),
            'addemptydir' => JitZipArchive::addEmptyDir($context, ...$args),
            'addfile' => JitZipArchive::addFile($context, ...$args),
            'getfromname' => JitZipArchive::getFromName($context, ...$args),
            'getfromindex' => JitZipArchive::getFromIndex($context, ...$args),
            'getnameindex' => JitZipArchive::getNameIndex($context, ...$args),
            'locatename' => JitZipArchive::locateName($context, ...$args),
            'renamename' => JitZipArchive::renameName($context, ...$args),
            'renameindex' => JitZipArchive::renameIndex($context, ...$args),
            'deletename' => JitZipArchive::deleteName($context, ...$args),
            'deleteindex' => JitZipArchive::deleteIndex($context, ...$args),
            'extractto' => JitZipArchive::extractTo($context, ...$args),
            'getstatusstring' => JitZipArchive::getStatusString($context, ...$args),
            'count' => JitZipArchive::count($context, ...$args),
            'iswritable' => JitZipArchive::isWritable($context, ...$args),
            'setreadonly' => JitZipArchive::setReadOnly($context, ...$args),
            'setarchivecomment' => JitZipArchive::setArchiveComment($context, ...$args),
            'getarchivecomment' => JitZipArchive::getArchiveComment($context, ...$args),
            'setcommentname' => JitZipArchive::setCommentName($context, ...$args),
            'getcommentname' => JitZipArchive::getCommentName($context, ...$args),
            'setcommentindex' => JitZipArchive::setCommentIndex($context, ...$args),
            'getcommentindex' => JitZipArchive::getCommentIndex($context, ...$args),
            'unchangeall' => JitZipArchive::unchangeAll($context, ...$args),
            'close' => JitZipArchive::close($context, ...$args),
            default => throw new \LogicException(
                'ZipArchive::'.$this->method.'() JIT dispatch missing (#35424/#35478/#35476/#35486/#35490)'
            ),
        };
    }
}
