<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * vfscanf() stream scanf helper (php-src ext/standard/scanf.c; issue #6174, #15992).
 */
final class VmVfscanf
{
    /**
     * @param list<\PHPCompiler\VM\Variable> $outVars
     */
    public static function parse(int $handle, string $format, array $outVars): int|false
    {
        $scanned = self::readAndScan($handle, $format, $outVars);
        if (false === $scanned) {
            return false;
        }
        [$data, $assigned] = $scanned;
        if (0 === $assigned && '' === $data) {
            return false;
        }

        return $assigned;
    }

    /** Two-arg fscanf()/vfscanf(): return parsed values as a list array (php-src ext/standard/fscanf.c, #9284). */
    public static function parseToArray(int $handle, string $format): HashTable|false|null
    {
        $slots = VmSscanf::countConversionSpecs($format);
        if (0 === $slots) {
            $scanned = self::readAndScan($handle, $format, []);
            if (false === $scanned) {
                return false;
            }
            [$data, $assigned] = $scanned;
            if (0 === $assigned) {
                if ('' === $data) {
                    return false;
                }
                if ('' === \trim($data)) {
                    return null;
                }
            }

            return new HashTable();
        }
        $temps = [];
        for ($i = 0; $i < $slots; ++$i) {
            $temps[] = new Variable();
        }
        $scanned = self::readAndScan($handle, $format, $temps);
        if (false === $scanned) {
            return false;
        }
        [$data, $assigned] = $scanned;
        if (0 === $assigned) {
            if ('' === $data) {
                return false;
            }
            if ('' === \trim($data)) {
                return null;
            }
        }
        $ht = new HashTable();
        $stored = $scanned[2] ?? $assigned;
        for ($i = 0; $i < $slots; ++$i) {
            $copy = new Variable();
            if ($i < $stored) {
                $copy->copyFrom($temps[$i]);
            } else {
                $copy->null();
            }
            $ht->append($copy);
        }

        return $ht;
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $outVars
     *
     * @return array{0: string, 1: int, 2: int}|false
     */
    private static function readAndScan(int $handle, string $format, array $outVars): array|false
    {
        if (self::streamSupportsTell($handle)) {
            return self::readAndScanSeekable($handle, $format, $outVars);
        }

        return self::readAndScanNonSeekable($handle, $format, $outVars);
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $outVars
     *
     * @return array{0: string, 1: int, 2: int}|false
     */
    private static function readAndScanSeekable(int $handle, string $format, array $outVars): array|false
    {
        $start = VmFs::ftell($handle);
        if (false === $start) {
            return false;
        }
        $data = VmFs::streamGetContents($handle, -1, -1);
        if (false === $data) {
            return false;
        }
        [$assigned, $consumed, $stored] = VmSscanf::parseWithConsumed($data, $format, $outVars);
        self::repositionStreamAfterScan($handle, $start, $data, $consumed);

        return [$data, $assigned, $stored];
    }

    /**
     * Non-tellable streams (php://stdin, pipes) — incremental read + pushback (#15992).
     *
     * @param list<\PHPCompiler\VM\Variable> $outVars
     *
     * @return array{0: string, 1: int, 2: int}|false
     */
    private static function readAndScanNonSeekable(int $handle, string $format, array $outVars): array|false
    {
        $buffer = '';
        while (true) {
            [$assigned, $consumed, $stored] = VmSscanf::parseWithConsumed($buffer, $format, $outVars);
            if ($assigned > 0) {
                if ($consumed < \strlen($buffer)) {
                    VmFs::pushbackUnread($handle, \substr($buffer, $consumed));
                }

                return [$buffer, $assigned, $stored];
            }
            if ('' !== $buffer && (VmFs::eof($handle) || ($consumed > 0 && 0 === $assigned))) {
                break;
            }
            $chunk = VmFs::fread($handle, 8192);
            if (false === $chunk) {
                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $buffer .= $chunk;
            if (\strlen($buffer) > 1_048_576) {
                break;
            }
        }
        [$assigned, $consumed, $stored] = VmSscanf::parseWithConsumed($buffer, $format, $outVars);
        if ($consumed < \strlen($buffer)) {
            VmFs::pushbackUnread($handle, \substr($buffer, $consumed));
        }

        return [$buffer, $assigned, $stored];
    }

    private static function streamSupportsTell(int $handle): bool
    {
        $uri = VmFs::handleUri($handle);
        if ('' !== $uri && !VmStreamMeta::supportsTell($uri)) {
            return false;
        }

        return false !== VmFs::ftell($handle);
    }

    /**
     * Advance stream position after scanf and set EOF when the scan consumed all remaining bytes.
     *
     * php-src ext/standard/scanf.c — feof() true after fscanf reads the last token (#11975).
     */
    private static function repositionStreamAfterScan(int $handle, int $start, string $data, int $consumed): void
    {
        if ($consumed <= 0) {
            return;
        }
        VmFs::fseek($handle, $start + $consumed, \SEEK_SET);
        if ($consumed >= \strlen($data)) {
            VmFs::fread($handle, 1);
        }
    }
}
