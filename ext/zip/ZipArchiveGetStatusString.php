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
        self::assignStatArray($frame->returnVar, $result);
    }

    /**
     * @param array<string, string|int> $result
     */
    public static function assignStatArray(Variable $returnVar, array $result): void
    {
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
        $returnVar->array($ht);
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

/**
 * ZipArchive::statIndex(int $index, int $flags = 0) — php-src php_zip.c (#19880).
 */
final class ZipArchiveStatIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('statIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::statIndex()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::statIndex() expects at least 1 argument, 0 given');
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::statIndex', 1, 'index');
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::statIndex', 2, 'flags')
            : 0;
        $result = VmZipArchive::statIndex($receiver, $index, $flags);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        ZipArchiveStatName::assignStatArray($frame->returnVar, $result);
    }
}

/**
 * ZipArchive::locateName(string $name, int $flags = 0) — php-src php_zip.c (#19880).
 */
final class ZipArchiveLocateName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('locateName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::locateName()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::locateName() expects at least 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::locateName', 1, 'name');
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::locateName', 2, 'flags')
            : 0;
        $result = VmZipArchive::locateName($receiver, $name, $flags);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }
}

/**
 * ZipArchive::getFromIndex(int $index, int $len = 0, int $flags = 0) — php-src php_zip.c (#19880).
 */
final class ZipArchiveGetFromIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getFromIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getFromIndex()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::getFromIndex() expects at least 1 argument, 0 given');
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::getFromIndex', 1, 'index');
        $len = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::getFromIndex', 2, 'len')
            : 0;
        $flags = \count($frame->calledArgs) >= 4
            ? $this->intArg($frame->calledArgs[3], 'ZipArchive::getFromIndex', 3, 'flags')
            : 0;
        $result = VmZipArchive::getFromIndex($receiver, $index, $len, $flags);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/**
 * ZipArchive::getNameIndex(int $index, int $flags = 0) — php-src php_zip.c (#19880).
 */
final class ZipArchiveGetNameIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getNameIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getNameIndex()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::getNameIndex() expects at least 1 argument, 0 given');
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::getNameIndex', 1, 'index');
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::getNameIndex', 2, 'flags')
            : 0;
        $result = VmZipArchive::getNameIndex($receiver, $index, $flags);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/**
 * ZipArchive::deleteName(string $name) — php-src php_zip.c (#19880).
 */
final class ZipArchiveDeleteName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('deleteName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::deleteName()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::deleteName() expects exactly 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::deleteName', 1, 'name');
        $ok = VmZipArchive::deleteName($receiver, $name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::deleteIndex(int $index) — php-src php_zip.c (#19880).
 */
final class ZipArchiveDeleteIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('deleteIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::deleteIndex()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::deleteIndex() expects exactly 1 argument, 0 given');
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::deleteIndex', 1, 'index');
        $ok = VmZipArchive::deleteIndex($receiver, $index);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::addEmptyDir(string $dirname, int $flags = 0) — php-src php_zip.c (#19880).
 */
final class ZipArchiveAddEmptyDir extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('addEmptyDir');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::addEmptyDir()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::addEmptyDir() expects at least 1 argument, 0 given');
        }
        $dirname = $this->stringArg($frame->calledArgs[1], 'ZipArchive::addEmptyDir', 1, 'dirname');
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::addEmptyDir', 2, 'flags')
            : 0;
        $ok = VmZipArchive::addEmptyDir($receiver, $dirname, $flags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::renameName(string $name, string $new_name) — php-src php_zip.c (#19880).
 */
final class ZipArchiveRenameName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('renameName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::renameName()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::renameName() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::renameName', 1, 'name');
        $newName = $this->stringArg($frame->calledArgs[2], 'ZipArchive::renameName', 2, 'new_name');
        $ok = VmZipArchive::renameName($receiver, $name, $newName);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::renameIndex(int $index, string $new_name) — php-src php_zip.c (#19880).
 */
final class ZipArchiveRenameIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('renameIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::renameIndex()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::renameIndex() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::renameIndex', 1, 'index');
        $newName = $this->stringArg($frame->calledArgs[2], 'ZipArchive::renameIndex', 2, 'new_name');
        $ok = VmZipArchive::renameIndex($receiver, $index, $newName);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::getStream(string $name) — php-src php_zip.c (#19880).
 */
final class ZipArchiveGetStream extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getStream');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getStream()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::getStream() expects exactly 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::getStream', 1, 'name');
        $handle = VmZipArchive::getStream($receiver, $name);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $handle) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($handle, $frame->vmContext);
    }
}
