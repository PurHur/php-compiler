<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** ZipArchive::getStatusString() — VM (#6414). */
final class ZipArchiveGetStatusString extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getStatusString');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getStatusString()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmZipArchive::getStatusString($receiver));
        }
    }
}

/**
 * ZipArchive::count() — Countable entry count (php-src ext/zip/php_zip.c; #19492).
 */
final class ZipArchiveCount extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::count()');
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::count() expects exactly 0 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmZipArchive::numFiles($receiver));
    }
}

/**
 * ZipArchive::statName(string $name, int $flags = 0) — php-src php_zip.c (#19873).
 */
final class ZipArchiveStatName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('statName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::statName()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::statName() expects at least 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::statName', 1, 'name');
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::statName', 2, 'flags')
            : 0;
        $result = VmZipArchive::statName($receiver, $name, $flags);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($result as $key => $value) {
            $slot = new Variable();
            if (\is_string($value)) {
                $slot->string($value);
            } else {
                $slot->int((int) $value);
            }
            $ht->add((string) $key, $slot);
        }
        $frame->returnVar->array($ht);
    }
}

/**
 * ZipArchive::setPassword(string $password) — php-src php_zip.c (#19873).
 */
final class ZipArchiveSetPassword extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setPassword');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setPassword()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::setPassword() expects exactly 1 argument, 0 given');
        }
        $password = $this->stringArg($frame->calledArgs[1], 'ZipArchive::setPassword', 1, 'password');
        $ok = VmZipArchive::setPassword($receiver, $password);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::setEncryptionName(string $name, int $method, ?string $password = null)
 * — php-src php_zip.c (#19873).
 */
final class ZipArchiveSetEncryptionName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setEncryptionName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setEncryptionName()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::setEncryptionName() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::setEncryptionName', 1, 'name');
        $method = $this->intArg($frame->calledArgs[2], 'ZipArchive::setEncryptionName', 2, 'method');
        $password = null;
        if ($argc >= 3) {
            $password = $this->nullableStringArg(
                $frame->calledArgs[3],
                'ZipArchive::setEncryptionName',
                3,
                'password'
            );
        }
        $ok = VmZipArchive::setEncryptionName($receiver, $name, $method, $password);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    private function nullableStringArg(Variable $var, string $label, int $index, string $paramName): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type ?string, %s given',
                $label,
                $index,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type ?string, %s given',
                $label,
                $index,
                $paramName,
                match ($var->type) {
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_DOUBLE => 'float',
                    Variable::TYPE_ARRAY => 'array',
                    Variable::TYPE_OBJECT => $var->toObject()->class->name,
                    Variable::TYPE_RESOURCE => 'resource',
                    default => 'mixed',
                }
            ));
        }

        return $var->toString();
    }
}
