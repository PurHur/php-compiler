<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\standard\VmFsWriteNative;

/**
 * Pure-PHP ZIP store/unstore engine (php-src ext/zip/php_zip.c subset; issue #6414).
 *
 * Supports method 0 (stored) archives for open/addFile/extractTo without libzip or host ZipArchive.
 */
final class ZipEngine
{
    private const SIG_LOCAL = 0x04034b50;

    private const SIG_CENTRAL = 0x02014b50;

    private const SIG_EOCD = 0x06054b50;

    /**
     * @return array{ok: true, entries: list<array{name: string, data: string, crc: int, size: int, comp_size: int, comp_method: int, comment?: string}>, comment: string}|array{ok: false, code: int}
     */
    public static function readArchive(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'code' => ZipArchiveConstants::ER_NOENT];
        }
        $data = VmFsReadNative::read($path);
        if (false === $data) {
            return ['ok' => false, 'code' => ZipArchiveConstants::ER_READ];
        }
        if ('' === $data) {
            return ['ok' => true, 'entries' => [], 'comment' => ''];
        }

        $eocd = self::findEocd($data);
        if (null === $eocd) {
            return ['ok' => false, 'code' => ZipArchiveConstants::ER_NOZIP];
        }

        $entries = [];
        $offset = $eocd['cdOffset'];
        $count = $eocd['cdCount'];
        for ($i = 0; $i < $count; ++$i) {
            if ($offset + 46 > strlen($data)) {
                return ['ok' => false, 'code' => ZipArchiveConstants::ER_INCONS];
            }
            $header = unpack('Vsig/vversion/vversionNeeded/vflags/vmethod/vmtime/vmdate/Vcrc/VcompSize/Vsize/vnameLen/vextraLen/vcommentLen/vdiskStart/vintAttr/VextAttr/VlocalOffset', substr($data, $offset, 46));
            if (!is_array($header) || self::SIG_CENTRAL !== ($header['sig'] ?? 0)) {
                return ['ok' => false, 'code' => ZipArchiveConstants::ER_INCONS];
            }
            $nameLen = (int) $header['nameLen'];
            $extraLen = (int) $header['extraLen'];
            $commentLen = (int) $header['commentLen'];
            $name = substr($data, $offset + 46, $nameLen);
            $fileComment = substr($data, $offset + 46 + $nameLen + $extraLen, $commentLen);
            $method = (int) $header['method'];
            if (0 !== $method) {
                return ['ok' => false, 'code' => ZipArchiveConstants::ER_COMPNOTSUPP];
            }
            $localOffset = (int) $header['localOffset'];
            $fileData = self::readLocalEntry($data, $localOffset, $name);
            if (null === $fileData) {
                return ['ok' => false, 'code' => ZipArchiveConstants::ER_INCONS];
            }
            $row = [
                'name' => $name,
                'data' => $fileData,
                'crc' => (int) $header['crc'],
                'size' => (int) $header['size'],
                'comp_size' => (int) $header['compSize'],
                'mtime' => self::unixFromDos((int) $header['mtime'], (int) $header['mdate']),
                'comp_method' => $method,
                'opsys' => ((int) $header['version'] >> 8) & 0xff,
                'external_attr' => (int) $header['extAttr'],
            ];
            if ('' !== $fileComment) {
                $row['comment'] = $fileComment;
            }
            $entries[] = $row;
            $offset += 46 + $nameLen + $extraLen + $commentLen;
        }

        return ['ok' => true, 'entries' => $entries, 'comment' => $eocd['comment']];
    }

    /**
     * @param list<array{
     *     name: string,
     *     data: string,
     *     crc: int,
     *     size: int,
     *     mtime?: int,
     *     opsys?: int,
     *     external_attr?: int,
     *     comment?: string
     * }> $entries
     */
    public static function writeArchive(string $path, array $entries, string $archiveComment = ''): bool
    {
        $binary = self::buildArchive($entries, $archiveComment);

        return false !== VmFsWriteNative::write($path, $binary);
    }

    /**
     * @param list<array{
     *     name: string,
     *     data: string,
     *     crc: int,
     *     size: int,
     *     mtime?: int,
     *     opsys?: int,
     *     external_attr?: int,
     *     comment?: string
     * }> $entries
     */
    public static function buildArchive(array $entries, string $archiveComment = ''): string
    {
        if (strlen($archiveComment) > 0xffff) {
            $archiveComment = substr($archiveComment, 0, 0xffff);
        }
        $local = '';
        $central = '';
        $offset = 0;
        foreach ($entries as $entry) {
            $name = $entry['name'];
            $data = $entry['data'];
            $comment = (string) ($entry['comment'] ?? '');
            if (strlen($comment) > 0xffff) {
                $comment = substr($comment, 0, 0xffff);
            }
            $size = strlen($data);
            $crc = self::crc32Unsigned($data);
            $mtime = (int) ($entry['mtime'] ?? time());
            $time = self::dosTime($mtime);
            $opsys = (int) ($entry['opsys'] ?? ZipArchiveConstants::OPSYS_DEFAULT);
            $extAttr = (int) ($entry['external_attr'] ?? 0);
            $versionMadeBy = 20 | (($opsys & 0xff) << 8);
            $localHeader = pack(
                'VvvvvvVVVvv',
                self::SIG_LOCAL,
                20,
                0,
                0,
                $time['time'],
                $time['date'],
                $crc,
                $size,
                $size,
                strlen($name),
                0
            );
            $chunk = $localHeader . $name . $data;
            $local .= $chunk;

            $centralHeader = pack(
                'VvvvvvvVVVvvvvvVV',
                self::SIG_CENTRAL,
                $versionMadeBy,
                20,
                0,
                0,
                $time['time'],
                $time['date'],
                $crc,
                $size,
                $size,
                strlen($name),
                0,
                strlen($comment),
                0,
                0,
                $extAttr,
                $offset
            );
            $central .= $centralHeader . $name . $comment;
            $offset += strlen($chunk);
        }

        $cdSize = strlen($central);
        $eocd = pack(
            'VvvvvVVv',
            self::SIG_EOCD,
            0,
            0,
            count($entries),
            count($entries),
            $cdSize,
            strlen($local),
            strlen($archiveComment)
        );

        return $local . $central . $eocd . $archiveComment;
    }

    /**
     * @return array{cdCount: int, cdOffset: int, comment: string}|null
     */
    private static function findEocd(string $data): ?array
    {
        $len = strlen($data);
        $maxComment = 65535;
        $start = max(0, $len - 22 - $maxComment);
        for ($pos = $len - 22; $pos >= $start; --$pos) {
            $sig = unpack('V', substr($data, $pos, 4));
            if (is_array($sig) && self::SIG_EOCD === ($sig[1] ?? 0)) {
                $fields = unpack('vdisk/vdiskStart/vcdCount/vtotal/VcdSize/VcdOffset/vcommentLen', substr($data, $pos + 4, 18));
                if (!is_array($fields)) {
                    return null;
                }
                $commentLen = (int) $fields['commentLen'];
                if ($pos + 22 + $commentLen > $len) {
                    return null;
                }

                return [
                    'cdCount' => (int) $fields['cdCount'],
                    'cdOffset' => (int) $fields['cdOffset'],
                    'comment' => substr($data, $pos + 22, $commentLen),
                ];
            }
        }

        return null;
    }

    private static function readLocalEntry(string $data, int $offset, string $expectedName): ?string
    {
        if ($offset + 30 > strlen($data)) {
            return null;
        }
        $header = unpack('Vsig/vversion/vflags/vmethod/vmtime/vmdate/Vcrc/VcompSize/Vsize/vnameLen/vextraLen', substr($data, $offset, 30));
        if (!is_array($header) || self::SIG_LOCAL !== ($header['sig'] ?? 0)) {
            return null;
        }
        $nameLen = (int) $header['nameLen'];
        $extraLen = (int) $header['extraLen'];
        $name = substr($data, $offset + 30, $nameLen);
        if ($name !== $expectedName) {
            return null;
        }
        $size = (int) $header['size'];
        $dataOffset = $offset + 30 + $nameLen + $extraLen;
        if ($dataOffset + $size > strlen($data)) {
            return null;
        }

        return substr($data, $dataOffset, $size);
    }

    /** @return array{time: int, date: int} */
    private static function dosTime(int $timestamp): array
    {
        $dt = getdate($timestamp);

        return [
            'time' => (($dt['hours'] << 11) | ($dt['minutes'] << 5) | ((int) ($dt['seconds'] / 2))),
            'date' => ((($dt['year'] - 1980) << 9) | ($dt['mon'] << 5) | $dt['mday']),
        ];
    }

    private static function unixFromDos(int $dosTime, int $dosDate): int
    {
        $sec = ($dosTime & 0x1f) * 2;
        $min = ($dosTime >> 5) & 0x3f;
        $hour = ($dosTime >> 11) & 0x1f;
        $day = $dosDate & 0x1f;
        $mon = ($dosDate >> 5) & 0x0f;
        $year = (($dosDate >> 9) & 0x7f) + 1980;
        if ($mon < 1 || $mon > 12 || $day < 1 || $day > 31) {
            return 0;
        }
        $ts = mktime($hour, $min, $sec, $mon, $day, $year);

        return false === $ts ? 0 : $ts;
    }

    private static function crc32Unsigned(string $data): int
    {
        $crc = crc32($data);
        if ($crc < 0) {
            $crc += 0x100000000;
        }

        return (int) $crc;
    }
}
