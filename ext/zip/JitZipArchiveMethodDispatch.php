<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ZipArchive thin-AOT method dispatch (#35424 family).
 *
 * Moved from lib/JIT/Call/ZipArchiveMethod so Call proxies do not import
 * ext/zip (#36204). php-src: ext/zip/php_zip.c.
 */
final class JitZipArchiveMethodDispatch
{
    public static function invoke(Context $context, string $method, JITVariable ...$args): Value
    {
        return match (strtolower($method)) {
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
            'unchangearchive' => JitZipArchive::unchangeArchive($context, ...$args),
            'unchangeindex' => JitZipArchive::unchangeIndex($context, ...$args),
            'unchangename' => JitZipArchive::unchangeName($context, ...$args),
            'replacefile' => JitZipArchive::replaceFile($context, ...$args),
            'iscompressionmethodsupported' => JitZipArchive::isCompressionMethodSupported($context, ...$args),
            'isencryptionmethodsupported' => JitZipArchive::isEncryptionMethodSupported($context, ...$args),
            'setpassword' => JitZipArchive::setPassword($context, ...$args),
            'setcompressionname' => JitZipArchive::setCompressionName($context, ...$args),
            'setcompressionindex' => JitZipArchive::setCompressionIndex($context, ...$args),
            'setencryptionname' => JitZipArchive::setEncryptionName($context, ...$args),
            'setencryptionindex' => JitZipArchive::setEncryptionIndex($context, ...$args),
            'statname' => JitZipArchive::statName($context, ...$args),
            'statindex' => JitZipArchive::statIndex($context, ...$args),
            'setmtimename' => JitZipArchive::setMtimeName($context, ...$args),
            'setmtimeindex' => JitZipArchive::setMtimeIndex($context, ...$args),
            'setexternalattributesname' => JitZipArchive::setExternalAttributesName($context, ...$args),
            'setexternalattributesindex' => JitZipArchive::setExternalAttributesIndex($context, ...$args),
            'getexternalattributesname' => JitZipArchive::getExternalAttributesName($context, ...$args),
            'getexternalattributesindex' => JitZipArchive::getExternalAttributesIndex($context, ...$args),
            'setarchiveflag' => JitZipArchive::setArchiveFlag($context, ...$args),
            'getarchiveflag' => JitZipArchive::getArchiveFlag($context, ...$args),
            'clearerror' => JitZipArchive::clearError($context, ...$args),
            'getstream' => JitZipArchive::getStream($context, ...$args),
            'getstreamindex' => JitZipArchive::getStreamIndex($context, ...$args),
            'getstreamname' => JitZipArchive::getStreamName($context, ...$args),
            'addglob' => JitZipArchive::addGlob($context, ...$args),
            'addpattern' => JitZipArchive::addPattern($context, ...$args),
            'registerprogresscallback' => JitZipArchive::registerProgressCallback($context, ...$args),
            'registercancelcallback' => JitZipArchive::registerCancelCallback($context, ...$args),
            'close' => JitZipArchive::close($context, ...$args),
            default => throw new \LogicException(
                'ZipArchive::'.$method.'() JIT dispatch missing (#35424/#35531/#35534/#35537/#35539)'
            ),
        };
    }
}
