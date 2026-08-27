<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\zip\JitZipArchive;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ZipArchive thin-AOT methods — open / add / close / get / locate / index (#35424 / #35437 / #35440).
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
            'getfromname' => JitZipArchive::getFromName($context, ...$args),
            'getfromindex' => JitZipArchive::getFromIndex($context, ...$args),
            'getnameindex' => JitZipArchive::getNameIndex($context, ...$args),
            'locatename' => JitZipArchive::locateName($context, ...$args),
            'close' => JitZipArchive::close($context, ...$args),
            default => throw new \LogicException(
                'ZipArchive::'.$this->method.'() JIT dispatch missing (#35424/#35437/#35440)'
            ),
        };
    }
}
