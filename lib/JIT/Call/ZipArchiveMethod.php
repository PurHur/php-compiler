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
 * extract / status / count / writable / archive comment / entry comment / unchange / replaceFile /
 * isCompressionMethodSupported / isEncryptionMethodSupported / setPassword / setCompression* /
 * setEncryption* / statName / statIndex / setMtimeName / setMtimeIndex
 * (#35424 / #35437 / #35440 / #35449 / #35450 / #35455 / #35465 / #35466 / #35467 / #35472 /
 * #35476 / #35478 / #35486 / #35489 / #35491 / #35496 / #35498 / #35500 / #35503 / #35504 / #35506 / #35508).
 *
 * php-src: ext/zip/php_zip.c
 */
final class ZipArchiveMethod implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> */
    public array $paramNames = [];

    /** Instance methods have implicit $this; static probes do not (#35498). */
    public int $namedArgsReceiverPrefix = 1;

    public function __construct(
        private readonly string $method,
    ) {
        $this->name = 'ZipArchive::'.$method;
        $lc = strtolower($method);
        if ('iscompressionmethodsupported' === $lc || 'isencryptionmethodsupported' === $lc) {
            $this->paramNames = ['method', 'enc='];
            $this->namedArgsReceiverPrefix = 0;
        } elseif ('setcompressionname' === $lc) {
            $this->paramNames = ['name', 'method', 'compflags='];
        } elseif ('setcompressionindex' === $lc) {
            $this->paramNames = ['index', 'method', 'compflags='];
        } elseif ('setencryptionname' === $lc) {
            $this->paramNames = ['name', 'method', 'password='];
        } elseif ('setencryptionindex' === $lc) {
            $this->paramNames = ['index', 'method', 'password='];
        }
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
            'close' => JitZipArchive::close($context, ...$args),
            default => throw new \LogicException(
                'ZipArchive::'.$this->method.'() JIT dispatch missing (#35424/#35503/#35504/#35506/#35508)'
            ),
        };
    }
}
