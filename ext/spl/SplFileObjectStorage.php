<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\VM\ObjectEntry;

/** Stream + iterator state for SplFileObject / SplTempFileObject (php-src ext/spl/spl_directory.c). */
final class SplFileObjectStorage
{
    public const FLAG_READ_AHEAD = 2;

    /** @var array<int, array{handle: int, currentLine: string|null, lineNum: int, flags: int, maxLineLen: int, separator: string, enclosure: string, escape: string}> */
    private static array $state = [];

    public static function setHandle(ObjectEntry $object, int $handle): void
    {
        self::$state[$object->id] = [
            'handle' => $handle,
            'currentLine' => null,
            'lineNum' => 0,
            'flags' => 0,
            'maxLineLen' => 0,
            'separator' => ',',
            'enclosure' => '"',
            'escape' => '\\',
        ];
    }

    public static function handle(ObjectEntry $object): int
    {
        return self::state($object)['handle'];
    }

    public static function hasHandle(ObjectEntry $object): bool
    {
        return isset(self::$state[$object->id]);
    }

    public static function rewind(ObjectEntry $object): void
    {
        $state = &self::$state[$object->id];
        if (!VmFs::rewind($state['handle'])) {
            throw new \RuntimeException('Cannot rewind file');
        }
        self::freeLine($state);
        $state['lineNum'] = 0;
        if (self::hasFlag($state, self::FLAG_READ_AHEAD)) {
            self::readLineForIterator($object, true);
        }
    }

    public static function next(ObjectEntry $object): void
    {
        $state = &self::$state[$object->id];
        if (null === $state['currentLine']) {
            if (!self::readLineForIterator($object, true)) {
                return;
            }
        }
        self::freeLine($state);
        if (self::hasFlag($state, self::FLAG_READ_AHEAD)) {
            self::readLineForIterator($object, true);
        }
        ++$state['lineNum'];
    }

    public static function valid(ObjectEntry $object): bool
    {
        $state = self::state($object);
        if (self::hasFlag($state, self::FLAG_READ_AHEAD)) {
            return null !== $state['currentLine'];
        }

        return !VmFs::feof($state['handle']);
    }

    public static function key(ObjectEntry $object): int
    {
        return self::state($object)['lineNum'];
    }

    /** @return string|false */
    public static function current(ObjectEntry $object)
    {
        $state = &self::$state[$object->id];
        if (null === $state['currentLine']) {
            if (!self::readLineForIterator($object, true)) {
                return false;
            }
        }

        return $state['currentLine'] ?? false;
    }

    public static function eof(ObjectEntry $object): bool
    {
        return VmFs::feof(self::state($object)['handle']);
    }

    /** @return array{0: string, 1: string, 2: string} */
    public static function getCsvControl(ObjectEntry $object): array
    {
        $state = self::state($object);

        return [$state['separator'], $state['enclosure'], $state['escape']];
    }

    public static function setCsvControl(
        ObjectEntry $object,
        string $separator,
        string $enclosure,
        string $escape,
    ): void {
        $state = &self::$state[$object->id];
        $state['separator'] = $separator;
        $state['enclosure'] = $enclosure;
        $state['escape'] = $escape;
    }

    /** @return string|false */
    public static function fgets(ObjectEntry $object, ?int $length = null)
    {
        $state = &self::$state[$object->id];
        if (null !== $state['currentLine']) {
            $line = $state['currentLine'];
            self::freeLine($state);
            ++$state['lineNum'];

            return $line;
        }
        if (!self::readLineEx($object, true, 1, $length)) {
            if (VmFs::feof($state['handle'])) {
                return '';
            }

            return false;
        }
        $line = $state['currentLine'];
        self::freeLine($state);

        return $line ?? false;
    }

    private static function readLineForIterator(ObjectEntry $object, bool $silent): bool
    {
        return self::readLine($object, $silent);
    }

    private static function readLine(ObjectEntry $object, bool $silent): bool
    {
        $state = &self::$state[$object->id];
        $lineAdd = null !== $state['currentLine'] ? 1 : 0;

        return self::readLineEx($object, $silent, $lineAdd, null);
    }

    private static function readLineEx(ObjectEntry $object, bool $silent, int $lineAdd, ?int $length): bool
    {
        $state = &self::$state[$object->id];
        self::freeLine($state);
        if (VmFs::feof($state['handle'])) {
            return false;
        }
        $readLen = $length ?? ($state['maxLineLen'] > 0 ? $state['maxLineLen'] : null);
        $line = VmFs::fgets($state['handle'], $readLen);
        if (false === $line) {
            return false;
        }
        $state['currentLine'] = $line;
        $state['lineNum'] += $lineAdd;

        return true;
    }

    /** @param array{handle: int, currentLine: string|null, lineNum: int, flags: int, maxLineLen: int} $state */
    private static function freeLine(array &$state): void
    {
        $state['currentLine'] = null;
    }

    /** @param array{handle: int, currentLine: string|null, lineNum: int, flags: int, maxLineLen: int, separator: string, enclosure: string, escape: string} $state */
    private static function hasFlag(array $state, int $flag): bool
    {
        return 0 !== ($state['flags'] & $flag);
    }

    /** @return array{handle: int, currentLine: string|null, lineNum: int, flags: int, maxLineLen: int, separator: string, enclosure: string, escape: string} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$state[$object->id])) {
            throw new \LogicException('SplFileObject stream handle missing');
        }

        return self::$state[$object->id];
    }
}
