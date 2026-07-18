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

/**
 * ZipArchive::setMtimeName(string $name, int $timestamp, int $flags = 0) — php-src php_zip.c (#20363).
 */
final class ZipArchiveSetMtimeName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setMtimeName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setMtimeName()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::setMtimeName() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::setMtimeName', 1, 'name');
        $timestamp = $this->intArg($frame->calledArgs[2], 'ZipArchive::setMtimeName', 2, 'timestamp');
        $flags = $argc >= 3
            ? $this->intArg($frame->calledArgs[3], 'ZipArchive::setMtimeName', 3, 'flags')
            : 0;
        $ok = VmZipArchive::setMtimeName($receiver, $name, $timestamp, $flags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::setMtimeIndex(int $index, int $timestamp, int $flags = 0) — php-src php_zip.c (#20363).
 */
final class ZipArchiveSetMtimeIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setMtimeIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setMtimeIndex()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::setMtimeIndex() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::setMtimeIndex', 1, 'index');
        $timestamp = $this->intArg($frame->calledArgs[2], 'ZipArchive::setMtimeIndex', 2, 'timestamp');
        $flags = $argc >= 3
            ? $this->intArg($frame->calledArgs[3], 'ZipArchive::setMtimeIndex', 3, 'flags')
            : 0;
        $ok = VmZipArchive::setMtimeIndex($receiver, $index, $timestamp, $flags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::setExternalAttributesName(string $name, int $opsys, int $attr, int $flags = 0)
 * — php-src php_zip.c (#20363).
 */
final class ZipArchiveSetExternalAttributesName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setExternalAttributesName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setExternalAttributesName()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::setExternalAttributesName() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::setExternalAttributesName', 1, 'name');
        $opsys = $this->intArg($frame->calledArgs[2], 'ZipArchive::setExternalAttributesName', 2, 'opsys');
        $attr = $this->intArg($frame->calledArgs[3], 'ZipArchive::setExternalAttributesName', 3, 'attr');
        $flags = $argc >= 4
            ? $this->intArg($frame->calledArgs[4], 'ZipArchive::setExternalAttributesName', 4, 'flags')
            : 0;
        $ok = VmZipArchive::setExternalAttributesName($receiver, $name, $opsys, $attr, $flags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::setExternalAttributesIndex(int $index, int $opsys, int $attr, int $flags = 0)
 * — php-src php_zip.c (#20363).
 */
final class ZipArchiveSetExternalAttributesIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setExternalAttributesIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setExternalAttributesIndex()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::setExternalAttributesIndex() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::setExternalAttributesIndex', 1, 'index');
        $opsys = $this->intArg($frame->calledArgs[2], 'ZipArchive::setExternalAttributesIndex', 2, 'opsys');
        $attr = $this->intArg($frame->calledArgs[3], 'ZipArchive::setExternalAttributesIndex', 3, 'attr');
        $flags = $argc >= 4
            ? $this->intArg($frame->calledArgs[4], 'ZipArchive::setExternalAttributesIndex', 4, 'flags')
            : 0;
        $ok = VmZipArchive::setExternalAttributesIndex($receiver, $index, $opsys, $attr, $flags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::getExternalAttributesName(string $name, &$opsys, &$attr, int $flags = 0)
 * — php-src php_zip.c (#20363).
 */
final class ZipArchiveGetExternalAttributesName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getExternalAttributesName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getExternalAttributesName()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::getExternalAttributesName() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::getExternalAttributesName', 1, 'name');
        $flags = $argc >= 4
            ? $this->intArg($frame->calledArgs[4], 'ZipArchive::getExternalAttributesName', 4, 'flags')
            : 0;
        $result = VmZipArchive::getExternalAttributesName($receiver, $name, $flags);
        if (false !== $result) {
            $frame->calledArgs[2]->resolveIndirect()->int($result['opsys']);
            $frame->calledArgs[3]->resolveIndirect()->int($result['attr']);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(false !== $result);
        }
    }
}

/**
 * ZipArchive::getExternalAttributesIndex(int $index, &$opsys, &$attr, int $flags = 0)
 * — php-src php_zip.c (#20363).
 */
final class ZipArchiveGetExternalAttributesIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getExternalAttributesIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getExternalAttributesIndex()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::getExternalAttributesIndex() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::getExternalAttributesIndex', 1, 'index');
        $flags = $argc >= 4
            ? $this->intArg($frame->calledArgs[4], 'ZipArchive::getExternalAttributesIndex', 4, 'flags')
            : 0;
        $result = VmZipArchive::getExternalAttributesIndex($receiver, $index, $flags);
        if (false !== $result) {
            $frame->calledArgs[2]->resolveIndirect()->int($result['opsys']);
            $frame->calledArgs[3]->resolveIndirect()->int($result['attr']);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(false !== $result);
        }
    }
}

/**
 * ZipArchive::setCompressionName(string $name, int $method, int $compflags = 0)
 * — php-src php_zip.c (#20363).
 */
final class ZipArchiveSetCompressionName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setCompressionName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setCompressionName()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::setCompressionName() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::setCompressionName', 1, 'name');
        $method = $this->intArg($frame->calledArgs[2], 'ZipArchive::setCompressionName', 2, 'method');
        $compflags = $argc >= 3
            ? $this->intArg($frame->calledArgs[3], 'ZipArchive::setCompressionName', 3, 'compflags')
            : 0;
        $ok = VmZipArchive::setCompressionName($receiver, $name, $method, $compflags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::setCompressionIndex(int $index, int $method, int $compflags = 0)
 * — php-src php_zip.c (#20363).
 */
final class ZipArchiveSetCompressionIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setCompressionIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setCompressionIndex()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::setCompressionIndex() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::setCompressionIndex', 1, 'index');
        $method = $this->intArg($frame->calledArgs[2], 'ZipArchive::setCompressionIndex', 2, 'method');
        $compflags = $argc >= 3
            ? $this->intArg($frame->calledArgs[3], 'ZipArchive::setCompressionIndex', 3, 'compflags')
            : 0;
        $ok = VmZipArchive::setCompressionIndex($receiver, $index, $method, $compflags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::isCompressionMethodSupported(int $method, bool $enc = true) — static (#20363).
 */
final class ZipArchiveIsCompressionMethodSupported extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('isCompressionMethodSupported');
    }

    public function execute(Frame $frame): void
    {
        // Static: calledArgs[0] is $method (no $this).
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'ZipArchive::isCompressionMethodSupported() expects at least 1 argument, 0 given'
            );
        }
        $method = $this->intArg($frame->calledArgs[0], 'ZipArchive::isCompressionMethodSupported', 1, 'method');
        $enc = true;
        if ($argc >= 2) {
            $enc = $this->boolArg($frame->calledArgs[1], 'ZipArchive::isCompressionMethodSupported', 2, 'enc');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmZipArchive::isCompressionMethodSupported($method, $enc));
        }
    }
}

/**
 * ZipArchive::isEncryptionMethodSupported(int $method, bool $enc = true) — static (#20378).
 */
final class ZipArchiveIsEncryptionMethodSupported extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('isEncryptionMethodSupported');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'ZipArchive::isEncryptionMethodSupported() expects at least 1 argument, 0 given'
            );
        }
        $method = $this->intArg($frame->calledArgs[0], 'ZipArchive::isEncryptionMethodSupported', 1, 'method');
        $enc = true;
        if ($argc >= 2) {
            $enc = $this->boolArg($frame->calledArgs[1], 'ZipArchive::isEncryptionMethodSupported', 2, 'enc');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmZipArchive::isEncryptionMethodSupported($method, $enc));
        }
    }
}

/**
 * ZipArchive::registerProgressCallback(float $rate, callable $callback) — (#20378).
 */
final class ZipArchiveRegisterProgressCallback extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('registerProgressCallback');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::registerProgressCallback()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::registerProgressCallback() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $rate = $this->floatArg($frame->calledArgs[1], 'ZipArchive::registerProgressCallback', 1, 'rate');
        $callback = $frame->calledArgs[2];
        if (null === $frame->vmContext) {
            throw new \LogicException('ZipArchive::registerProgressCallback() requires VM context');
        }
        // Accept string/array/closure callables; invoke-time validates (#20378 honest subset).
        $cbResolved = $callback->resolveIndirect();
        if (
            Variable::TYPE_STRING !== $cbResolved->type
            && Variable::TYPE_ARRAY !== $cbResolved->type
            && Variable::TYPE_OBJECT !== $cbResolved->type
            && !\PHPCompiler\ext\standard\VmCallable::isCallable($frame->vmContext, $callback)
        ) {
            throw new \TypeError(
                \PHPCompiler\ext\standard\VmCallable::invalidCallbackTypeError('ZipArchive::registerProgressCallback')
            );
        }
        $ok = VmZipArchive::registerProgressCallback($receiver, $rate, $callback);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::registerCancelCallback(callable $callback) — (#20378).
 */
final class ZipArchiveRegisterCancelCallback extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('registerCancelCallback');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::registerCancelCallback()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'ZipArchive::registerCancelCallback() expects exactly 1 argument, 0 given'
            );
        }
        $callback = $frame->calledArgs[1];
        if (null === $frame->vmContext) {
            throw new \LogicException('ZipArchive::registerCancelCallback() requires VM context');
        }
        $cbResolved = $callback->resolveIndirect();
        if (
            Variable::TYPE_STRING !== $cbResolved->type
            && Variable::TYPE_ARRAY !== $cbResolved->type
            && Variable::TYPE_OBJECT !== $cbResolved->type
            && !\PHPCompiler\ext\standard\VmCallable::isCallable($frame->vmContext, $callback)
        ) {
            throw new \TypeError(
                \PHPCompiler\ext\standard\VmCallable::invalidCallbackTypeError('ZipArchive::registerCancelCallback')
            );
        }
        $ok = VmZipArchive::registerCancelCallback($receiver, $callback);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::getStreamIndex(int $index, int $flags = 0) — (#20378).
 */
final class ZipArchiveGetStreamIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getStreamIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getStreamIndex()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::getStreamIndex() expects at least 1 argument, 0 given');
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::getStreamIndex', 1, 'index');
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::getStreamIndex', 2, 'flags')
            : 0;
        $handle = VmZipArchive::getStreamIndex($receiver, $index, $flags);
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

/**
 * ZipArchive::getStreamName(string $name, int $flags = 0) — (#20378).
 */
final class ZipArchiveGetStreamName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getStreamName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getStreamName()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::getStreamName() expects at least 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::getStreamName', 1, 'name');
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::getStreamName', 2, 'flags')
            : 0;
        $handle = VmZipArchive::getStreamName($receiver, $name, $flags);
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

/**
 * ZipArchive::clearError() — (#20378).
 */
final class ZipArchiveClearError extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('clearError');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::clearError()');
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::clearError() expects exactly 0 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        VmZipArchive::clearError($receiver);
    }
}

/**
 * ZipArchive::setEncryptionIndex(int $index, int $method, ?string $password = null) — (#20378).
 */
final class ZipArchiveSetEncryptionIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setEncryptionIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setEncryptionIndex()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::setEncryptionIndex() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::setEncryptionIndex', 1, 'index');
        $method = $this->intArg($frame->calledArgs[2], 'ZipArchive::setEncryptionIndex', 2, 'method');
        $password = null;
        if ($argc >= 3) {
            $password = $this->nullableStringArg(
                $frame->calledArgs[3],
                'ZipArchive::setEncryptionIndex',
                3,
                'password'
            );
        }
        $ok = VmZipArchive::setEncryptionIndex($receiver, $index, $method, $password);
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
                    Variable::TYPE_FLOAT => 'float',
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
 * ZipArchive::setCommentName(string $name, string $comment) — (#20386).
 */
final class ZipArchiveSetCommentName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setCommentName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setCommentName()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::setCommentName() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::setCommentName', 1, 'name');
        $comment = $this->stringArg($frame->calledArgs[2], 'ZipArchive::setCommentName', 2, 'comment');
        $ok = VmZipArchive::setCommentName($receiver, $name, $comment);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::setCommentIndex(int $index, string $comment) — (#20386).
 */
final class ZipArchiveSetCommentIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setCommentIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setCommentIndex()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::setCommentIndex() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::setCommentIndex', 1, 'index');
        $comment = $this->stringArg($frame->calledArgs[2], 'ZipArchive::setCommentIndex', 2, 'comment');
        $ok = VmZipArchive::setCommentIndex($receiver, $index, $comment);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::getCommentName(string $name, int $flags = 0) — (#20386).
 */
final class ZipArchiveGetCommentName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getCommentName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getCommentName()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::getCommentName() expects at least 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::getCommentName', 1, 'name');
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::getCommentName', 2, 'flags')
            : 0;
        $result = VmZipArchive::getCommentName($receiver, $name, $flags);
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
 * ZipArchive::getCommentIndex(int $index, int $flags = 0) — (#20386).
 */
final class ZipArchiveGetCommentIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getCommentIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getCommentIndex()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::getCommentIndex() expects at least 1 argument, 0 given');
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::getCommentIndex', 1, 'index');
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::getCommentIndex', 2, 'flags')
            : 0;
        $result = VmZipArchive::getCommentIndex($receiver, $index, $flags);
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
 * ZipArchive::setArchiveComment(string $comment) — (#20386).
 */
final class ZipArchiveSetArchiveComment extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('setArchiveComment');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::setArchiveComment()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::setArchiveComment() expects exactly 1 argument, 0 given');
        }
        $comment = $this->stringArg($frame->calledArgs[1], 'ZipArchive::setArchiveComment', 1, 'comment');
        $ok = VmZipArchive::setArchiveComment($receiver, $comment);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::getArchiveComment(int $flags = 0) — (#20386).
 */
final class ZipArchiveGetArchiveComment extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('getArchiveComment');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::getArchiveComment()');
        $flags = \count($frame->calledArgs) >= 2
            ? $this->intArg($frame->calledArgs[1], 'ZipArchive::getArchiveComment', 1, 'flags')
            : 0;
        $result = VmZipArchive::getArchiveComment($receiver, $flags);
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
 * ZipArchive::unchangeArchive() — (#20387).
 */
final class ZipArchiveUnchangeArchive extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('unchangeArchive');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::unchangeArchive()');
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::unchangeArchive() expects exactly 0 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        $ok = VmZipArchive::unchangeArchive($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::unchangeAll() — (#20387).
 */
final class ZipArchiveUnchangeAll extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('unchangeAll');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::unchangeAll()');
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::unchangeAll() expects exactly 0 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        $ok = VmZipArchive::unchangeAll($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::unchangeName(string $name) — (#20387).
 */
final class ZipArchiveUnchangeName extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('unchangeName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::unchangeName()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::unchangeName() expects exactly 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'ZipArchive::unchangeName', 1, 'name');
        $ok = VmZipArchive::unchangeName($receiver, $name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::unchangeIndex(int $index) — (#20387).
 */
final class ZipArchiveUnchangeIndex extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('unchangeIndex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::unchangeIndex()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::unchangeIndex() expects exactly 1 argument, 0 given');
        }
        $index = $this->intArg($frame->calledArgs[1], 'ZipArchive::unchangeIndex', 1, 'index');
        $ok = VmZipArchive::unchangeIndex($receiver, $index);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::replaceFile(string $filepath, int $index, int $start = 0, int $length = LENGTH_TO_END, int $flags = 0) — (#20387).
 */
final class ZipArchiveReplaceFile extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('replaceFile');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::replaceFile()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'ZipArchive::replaceFile() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        $filepath = $this->stringArg($frame->calledArgs[1], 'ZipArchive::replaceFile', 1, 'filepath');
        $index = $this->intArg($frame->calledArgs[2], 'ZipArchive::replaceFile', 2, 'index');
        $start = $argc >= 3
            ? $this->intArg($frame->calledArgs[3], 'ZipArchive::replaceFile', 3, 'start')
            : 0;
        $length = $argc >= 4
            ? $this->intArg($frame->calledArgs[4], 'ZipArchive::replaceFile', 4, 'length')
            : ZipArchiveConstants::LENGTH_TO_END;
        $flags = $argc >= 5
            ? $this->intArg($frame->calledArgs[5], 'ZipArchive::replaceFile', 5, 'flags')
            : 0;
        $ok = VmZipArchive::replaceFile($receiver, $filepath, $index, $start, $length, $flags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/**
 * ZipArchive::addGlob(string $pattern, int $flags = 0, array $options = []) — (#20387).
 */
final class ZipArchiveAddGlob extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('addGlob');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::addGlob()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::addGlob() expects at least 1 argument, 0 given');
        }
        $pattern = $this->stringArg($frame->calledArgs[1], 'ZipArchive::addGlob', 1, 'pattern');
        $flags = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'ZipArchive::addGlob', 2, 'flags')
            : 0;
        $options = [];
        if (\count($frame->calledArgs) >= 4) {
            $optVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $optVar->type) {
                throw new \TypeError(\sprintf(
                    'ZipArchive::addGlob(): Argument #3 ($options) must be of type array, %s given',
                    EnumCaseSupport::typeNameForVariable($optVar)
                ));
            }
            // Honest subset: options ignored (#20387).
            $options = [];
        }
        $result = VmZipArchive::addGlob($receiver, $pattern, $flags, $options);
        self::assignStringListOrFalse($frame->returnVar, $result);
    }

    /**
     * @param list<string>|false $result
     */
    public static function assignStringListOrFalse(?Variable $returnVar, array|false $result): void
    {
        if (null === $returnVar) {
            return;
        }
        if (false === $result) {
            $returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($result as $i => $name) {
            $slot = new Variable();
            $slot->string($name);
            $ht->add((string) $i, $slot);
        }
        $returnVar->array($ht);
    }
}

/**
 * ZipArchive::addPattern(string $pattern, string $path = ".", array $options = []) — (#20387).
 */
final class ZipArchiveAddPattern extends ZipClassMethod
{
    public function __construct()
    {
        parent::__construct('addPattern');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'ZipArchive::addPattern()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('ZipArchive::addPattern() expects at least 1 argument, 0 given');
        }
        $pattern = $this->stringArg($frame->calledArgs[1], 'ZipArchive::addPattern', 1, 'pattern');
        $path = \count($frame->calledArgs) >= 3
            ? $this->stringArg($frame->calledArgs[2], 'ZipArchive::addPattern', 2, 'path')
            : '.';
        $options = [];
        if (\count($frame->calledArgs) >= 4) {
            $optVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $optVar->type) {
                throw new \TypeError(\sprintf(
                    'ZipArchive::addPattern(): Argument #3 ($options) must be of type array, %s given',
                    EnumCaseSupport::typeNameForVariable($optVar)
                ));
            }
            $options = [];
        }
        $result = VmZipArchive::addPattern($receiver, $pattern, $path, $options);
        ZipArchiveAddGlob::assignStringListOrFalse($frame->returnVar, $result);
    }
}
