<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ZipArchive thin-AOT methods — open / add / close / get / locate / index / rename / delete /
 * extract / status / count / writable / archive comment / entry comment / unchange / replaceFile /
 * isCompressionMethodSupported / isEncryptionMethodSupported / setPassword / setCompression* /
 * setEncryption* / setExternalAttributes* / statName / statIndex / setMtimeName / setMtimeIndex /
 * setArchiveFlag / getArchiveFlag / clearError / getStream* / registerProgressCallback /
 * registerCancelCallback
 * (#35424 / #35437 / #35440 / #35449 / #35450 / #35455 / #35465 / #35466 / #35467 / #35472 /
 * #35476 / #35478 / #35486 / #35489 / #35491 / #35496 / #35498 / #35500 / #35503 / #35504 /
 * #35506 / #35508 / #35515 / #35522 / #35531 / #35534 / #35539).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\zip} (#36204). php-src: ext/zip/php_zip.c
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
        } elseif ('setexternalattributesname' === $lc) {
            $this->paramNames = ['name', 'opsys', 'attr', 'flags='];
        } elseif ('setexternalattributesindex' === $lc) {
            $this->paramNames = ['index', 'opsys', 'attr', 'flags='];
        } elseif ('getexternalattributesname' === $lc) {
            $this->paramNames = ['name', 'opsys', 'attr', 'flags='];
        } elseif ('getexternalattributesindex' === $lc) {
            $this->paramNames = ['index', 'opsys', 'attr', 'flags='];
        } elseif ('setmtimename' === $lc) {
            $this->paramNames = ['name', 'timestamp', 'flags='];
        } elseif ('setmtimeindex' === $lc) {
            $this->paramNames = ['index', 'timestamp', 'flags='];
        } elseif ('setarchiveflag' === $lc) {
            $this->paramNames = ['flag', 'value'];
        } elseif ('getarchiveflag' === $lc) {
            $this->paramNames = ['flag', 'flags='];
        } elseif ('registerprogresscallback' === $lc) {
            $this->paramNames = ['rate', 'callback'];
        } elseif ('registercancelcallback' === $lc) {
            $this->paramNames = ['callback'];
        }
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireZip()->zipArchiveMethod(
            $context,
            $this->method,
            ...$args
        );
    }
}
