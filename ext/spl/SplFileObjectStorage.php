<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmCsv;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\VM\ObjectEntry;

/** Stream + iterator state for SplFileObject / SplTempFileObject (php-src ext/spl/spl_directory.c). */
final class SplFileObjectStorage
{
    private const FLAG_READ_AHEAD = SplFileObjectBuiltin::READ_AHEAD;

    private const FLAG_READ_CSV = SplFileObjectBuiltin::READ_CSV;

    /**
     * @var array<int, array{
     *     handle: int,
     *     openMode: string,
     *     currentLine: string|null,
     *     currentCsv: list<string|null>|null,
     *     lineNum: int,
     *     flags: int,
     *     maxLineLen: int,
     *     separator: string,
     *     enclosure: string,
     *     escape: string
     * }>
     */
    private static array $state = [];

    public static function setHandle(ObjectEntry $object, int $handle, string $openMode = 'r'): void
    {
        self::$state[$object->id] = [
            'handle' => $handle,
            'openMode' => $openMode,
            'currentLine' => null,
            'currentCsv' => null,
            'lineNum' => 0,
            'flags' => 0,
            'maxLineLen' => 0,
            'separator' => ',',
            'enclosure' => '"',
            'escape' => '\\',
        ];
    }

    public static function openMode(ObjectEntry $object): string
    {
        return self::state($object)['openMode'];
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
        if (null === $state['currentLine'] && null === $state['currentCsv']) {
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
            return null !== $state['currentLine'] || null !== $state['currentCsv'];
        }

        return !VmFs::feof($state['handle']);
    }

    public static function key(ObjectEntry $object): int
    {
        return self::state($object)['lineNum'];
    }

    /**
     * Iterator current — string lines, or CSV field arrays when READ_CSV (#19663).
     *
     * @return string|list<string|null>|false
     */
    public static function current(ObjectEntry $object)
    {
        $state = &self::$state[$object->id];
        if (self::hasFlag($state, self::FLAG_READ_CSV)) {
            if (null === $state['currentCsv']) {
                if (!self::readLineForIterator($object, true)) {
                    // php-src spl_filesystem_file_read_line — BOF before first line is '' not false (#18429).
                    if (0 === $state['lineNum']) {
                        return '';
                    }

                    return false;
                }
            }

            return $state['currentCsv'] ?? false;
        }
        if (null === $state['currentLine']) {
            if (!self::readLineForIterator($object, true)) {
                // php-src spl_filesystem_file_read_line — BOF before first line is '' not false (#18429).
                if (0 === $state['lineNum']) {
                    return '';
                }

                return false;
            }
        }

        return $state['currentLine'] ?? false;
    }

    public static function eof(ObjectEntry $object): bool
    {
        return VmFs::feof(self::state($object)['handle']);
    }

    public static function seek(ObjectEntry $object, int $line): void
    {
        $state = &self::$state[$object->id];
        if ($line < 0) {
            throw new \ValueError('SplFileObject::seek(): Argument #1 ($line) must be greater than or equal to 0');
        }
        // php-src SplFileObject::seek — rewind, read_line line times, then bump key (#25321).
        if (!VmFs::rewind($state['handle'])) {
            throw new \RuntimeException('Cannot rewind file');
        }
        self::freeLine($state);
        $state['lineNum'] = 0;
        for ($i = 0; $i < $line; ++$i) {
            if (!self::readLineForIterator($object, true)) {
                // Early return on EOF — leave key at last successful read_line index.
                return;
            }
        }
        if ($line > 0 && !self::hasFlag($state, self::FLAG_READ_AHEAD)) {
            ++$state['lineNum'];
            self::freeLine($state);
        }
    }

    public static function fseek(ObjectEntry $object, int $offset, int $whence = \SEEK_SET): int
    {
        $state = &self::$state[$object->id];
        $result = VmFs::fseek($state['handle'], $offset, $whence);
        if (-1 === $result) {
            return -1;
        }
        self::freeLine($state);
        if (\SEEK_END === $whence) {
            // php-src ext/spl/spl_directory.c — SEEK_END keeps iterator line index; EOF current is '' (#14253).
            $state['currentLine'] = '';

            return 0;
        }
        self::syncLineNumFromHandle($state);
        if (self::hasFlag($state, self::FLAG_READ_AHEAD)) {
            self::readLineForIterator($object, true);
        }

        return 0;
    }

    /** @return int|false */
    public static function ftell(ObjectEntry $object)
    {
        return VmFs::ftell(self::state($object)['handle']);
    }

    /** @return \PHPCompiler\VM\HashTable|false */
    public static function fstat(ObjectEntry $object)
    {
        return VmFs::fstat(self::state($object)['handle']);
    }

    public static function flock(ObjectEntry $object, int $operation): bool
    {
        return VmFs::flock(self::state($object)['handle'], $operation);
    }

    public static function fflush(ObjectEntry $object): bool
    {
        return VmFs::fflush(self::state($object)['handle']);
    }

    public static function ftruncate(ObjectEntry $object, int $size): bool
    {
        self::freeLine(self::$state[$object->id]);

        return VmFs::ftruncate(self::state($object)['handle'], $size);
    }

    /** @return int|false */
    public static function fpassthru(ObjectEntry $object)
    {
        self::freeLine(self::$state[$object->id]);

        return VmFs::fpassthru(self::state($object)['handle']);
    }

    public static function getMaxLineLen(ObjectEntry $object): int
    {
        return self::state($object)['maxLineLen'];
    }

    public static function setMaxLineLen(ObjectEntry $object, int $maxLength): void
    {
        if ($maxLength < 0) {
            throw new \ValueError(
                'SplFileObject::setMaxLineLen(): Argument #1 ($maxLength) must be greater than or equal to 0'
            );
        }
        self::$state[$object->id]['maxLineLen'] = $maxLength;
    }

    /** @return string|false */
    public static function getCurrentLine(ObjectEntry $object)
    {
        return self::fgets($object);
    }

    /**
     * Read from current stream position through EOF (php-src spl_filesystem_file_read_all; #13610).
     */
    public static function readAll(ObjectEntry $object): string
    {
        $handle = self::state($object)['handle'];
        $buf = '';
        while (!VmFs::feof($handle)) {
            $chunk = VmFs::fread($handle, 8192);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $buf .= $chunk;
        }

        return $buf;
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

    public static function getFlags(ObjectEntry $object): int
    {
        return self::state($object)['flags'];
    }

    public static function setFlags(ObjectEntry $object, int $flags): void
    {
        self::$state[$object->id]['flags'] = $flags;
    }

    /** @return string|false */
    public static function fgets(ObjectEntry $object, ?int $length = null)
    {
        $state = &self::$state[$object->id];
        $lineAdd = (null !== $state['currentLine'] || null !== $state['currentCsv']) ? 1 : 0;
        self::freeLine($state);
        if (VmFs::feof($state['handle'])) {
            return '';
        }
        // php-src SplFileObject::fgets — always line-oriented; READ_CSV only affects iterator current (#19663).
        if (!self::readPlainLineEx($object, true, $lineAdd, $length)) {
            if (VmFs::feof($state['handle'])) {
                return '';
            }

            return false;
        }

        return $state['currentLine'] ?? false;
    }

    /**
     * php-src SplFileObject::fgetcsv — spl_filesystem_file_read_csv (#24290).
     * Trailing empty line after a final newline is array(null), not false.
     *
     * @return list<string|null>|false
     */
    public static function fgetcsv(
        ObjectEntry $object,
        string $separator,
        string $enclosure,
        string $escape,
    ): array|false {
        $state = &self::$state[$object->id];
        $lineAdd = (null !== $state['currentLine'] || null !== $state['currentCsv']) ? 1 : 0;
        $savedSep = $state['separator'];
        $savedEnc = $state['enclosure'];
        $savedEsc = $state['escape'];
        $state['separator'] = $separator;
        $state['enclosure'] = $enclosure;
        $state['escape'] = $escape;
        $readLen = $state['maxLineLen'] > 0 ? $state['maxLineLen'] + 1 : null;
        try {
            if (!self::readCsvLineEx($object, $lineAdd, $readLen)) {
                return false;
            }

            return $state['currentCsv'] ?? false;
        } finally {
            $state['separator'] = $savedSep;
            $state['enclosure'] = $savedEnc;
            $state['escape'] = $savedEsc;
        }
    }

    private static function readLineForIterator(ObjectEntry $object, bool $silent): bool
    {
        return self::readLine($object, $silent);
    }

    private static function readLine(ObjectEntry $object, bool $silent): bool
    {
        $state = &self::$state[$object->id];
        $lineAdd = (null !== $state['currentLine'] || null !== $state['currentCsv']) ? 1 : 0;

        return self::readLineEx($object, $silent, $lineAdd, null);
    }

    private static function readLineEx(ObjectEntry $object, bool $silent, int $lineAdd, ?int $length): bool
    {
        $state = &self::$state[$object->id];
        self::freeLine($state);
        if (VmFs::feof($state['handle'])) {
            return false;
        }
        $readLen = $length;
        if (null === $readLen && $state['maxLineLen'] > 0) {
            // php-src max_line_len+1 buffer for get_line (#19665).
            $readLen = $state['maxLineLen'] + 1;
        }
        if (self::hasFlag($state, self::FLAG_READ_CSV)) {
            return self::readCsvLineEx($object, $lineAdd, $readLen);
        }

        return self::readPlainLineEx($object, $silent, $lineAdd, $readLen);
    }

    private static function readPlainLineEx(ObjectEntry $object, bool $silent, int $lineAdd, ?int $length): bool
    {
        $state = &self::$state[$object->id];
        // php-src uses max_line_len+1 as php_stream_get_line buffer size (#19665).
        if (null === $length && $state['maxLineLen'] > 0) {
            $readLen = $state['maxLineLen'] + 1;
        } else {
            $readLen = $length;
        }
        do {
            // php-src 8.2 spl_filesystem_file_read_ex: fail only when already at EOF
            // before the read attempt. A NULL get_line while !eof is SUCCESS with
            // empty current_line — that is the trailing empty line after a final
            // newline (#24331; master php-src later returns FAILURE on NULL).
            if (VmFs::feof($state['handle'])) {
                return false;
            }
            $line = VmFs::fgets($state['handle'], $readLen);
            if (false === $line) {
                $line = '';
            }
            $line = self::applyDropNewLine($line, $state['flags']);
            if (self::shouldSkipEmptyLine($state['flags'], $line)) {
                continue;
            }
            $state['currentLine'] = $line;
            $state['lineNum'] += $lineAdd;

            return true;
        } while (true);
    }

    /**
     * php-src spl_filesystem_file_read_csv — read line then php_fgetcsv on buffer (#19663).
     *
     * @param ?int $length max line length (0 / null = unlimited)
     */
    private static function readCsvLineEx(ObjectEntry $object, int $lineAdd, ?int $length): bool
    {
        $state = &self::$state[$object->id];
        do {
            // php-src spl_filesystem_file_read_ex — fail only when already at EOF.
            if (VmFs::feof($state['handle'])) {
                return false;
            }
            $line = VmFs::fgets($state['handle'], $length);
            // get_line NULL while !eof → empty current_line (still SUCCESS), then empty CSV row.
            if (false === $line) {
                $line = '';
            }
            // php_fgetcsv_lookup_trailing_spaces — strip line terminators before field parse.
            $line = \rtrim($line, "\r\n");
            // csv=true path does not DROP_NEW_LINE on the buffer before parse (php-src).
            $row = VmCsv::parseLine(
                $line,
                $state['separator'],
                $state['enclosure'],
                $state['escape']
            );
            if (self::shouldSkipEmptyCsvRow($state['flags'], $row)) {
                continue;
            }
            $state['currentCsv'] = $row;
            $state['currentLine'] = null;
            $state['lineNum'] += $lineAdd;

            return true;
        } while (true);
    }

    /** @param list<string|null> $row */
    private static function shouldSkipEmptyCsvRow(int $flags, array $row): bool
    {
        if (0 === ($flags & SplFileObjectBuiltin::SKIP_EMPTY)) {
            return false;
        }
        // php-src: empty CSV line is a single null field — skip when SKIP_EMPTY.
        return 1 === \count($row) && null === $row[0];
    }

    private static function applyDropNewLine(string $line, int $flags): string
    {
        if (0 === ($flags & SplFileObjectBuiltin::DROP_NEW_LINE)) {
            return $line;
        }
        if ('' === $line) {
            return $line;
        }
        if (str_ends_with($line, "\r\n")) {
            return substr($line, 0, -2);
        }
        if (str_ends_with($line, "\n") || str_ends_with($line, "\r")) {
            return substr($line, 0, -1);
        }

        return $line;
    }

    private static function shouldSkipEmptyLine(int $flags, string $line): bool
    {
        return 0 !== ($flags & SplFileObjectBuiltin::SKIP_EMPTY) && '' === $line;
    }

    /** @param array{handle: int, currentLine: string|null, lineNum: int, flags: int, maxLineLen: int, separator: string, enclosure: string, escape: string} $state */
    private static function syncLineNumFromHandle(array &$state): void
    {
        $pos = VmFs::ftell($state['handle']);
        if (false === $pos || $pos <= 0) {
            $state['lineNum'] = 0;

            return;
        }
        $saved = $pos;
        if (!VmFs::rewind($state['handle'])) {
            $state['lineNum'] = 0;

            return;
        }
        $prefix = VmFs::fread($state['handle'], $saved);
        if (false === $prefix) {
            $state['lineNum'] = 0;
            VmFs::fseek($state['handle'], $saved, \SEEK_SET);

            return;
        }
        $state['lineNum'] = substr_count($prefix, "\n");
        VmFs::fseek($state['handle'], $saved, \SEEK_SET);
    }

    /**
     * @param array{
     *     handle: int,
     *     currentLine: string|null,
     *     currentCsv: list<string|null>|null,
     *     lineNum: int,
     *     flags: int,
     *     maxLineLen: int,
     *     separator: string,
     *     enclosure: string,
     *     escape: string
     * } $state
     */
    private static function freeLine(array &$state): void
    {
        $state['currentLine'] = null;
        $state['currentCsv'] = null;
    }

    /**
     * @param array{
     *     handle: int,
     *     currentLine: string|null,
     *     currentCsv: list<string|null>|null,
     *     lineNum: int,
     *     flags: int,
     *     maxLineLen: int,
     *     separator: string,
     *     enclosure: string,
     *     escape: string
     * } $state
     */
    private static function hasFlag(array $state, int $flag): bool
    {
        return 0 !== ($state['flags'] & $flag);
    }

    /**
     * @return array{
     *     handle: int,
     *     currentLine: string|null,
     *     currentCsv: list<string|null>|null,
     *     lineNum: int,
     *     flags: int,
     *     maxLineLen: int,
     *     separator: string,
     *     enclosure: string,
     *     escape: string
     * }
     */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$state[$object->id])) {
            throw new \LogicException('SplFileObject stream handle missing');
        }

        return self::$state[$object->id];
    }
}
