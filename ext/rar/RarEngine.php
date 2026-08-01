<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\standard\VmFsWriteNative;

/**
 * Pure-PHP RAR 1.5/4 store (method 0x30) engine — pecl-rar subset (#6237).
 *
 * No unrar/libabi; compressed / solid / encrypted archives are rejected.
 * Reference: RAR 1.5 block layout (HEAD_CRC / HEAD_TYPE / HEAD_FLAGS / HEAD_SIZE).
 */
final class RarEngine
{
    private const MARKER = "Rar!\x1a\x07\x00";

    private const HEAD_MARKER = 0x72;

    private const HEAD_ARCHIVE = 0x73;

    private const HEAD_FILE = 0x74;

    private const HEAD_END = 0x7b;

    private const FLAG_ADD_SIZE = 0x8000;

    private const METHOD_STORE = 0x30;

    private const HOST_UNIX = 3;

    /**
     * @return array{
     *   ok: true,
     *   entries: list<array{name: string, data: string, crc: int, packed: int, unpacked: int, hostOs: int, method: int, isDir: bool}>,
     *   broken: bool,
     *   solid: bool,
     *   comment: string
     * }|array{ok: false, message: string}
     */
    public static function readArchive(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'message' => 'Failed to open '.$path];
        }
        $data = VmFsReadNative::read($path);
        if (false === $data) {
            return ['ok' => false, 'message' => 'Failed to read '.$path];
        }

        return self::readArchiveBytes($data);
    }

    /**
     * @return array{
     *   ok: true,
     *   entries: list<array{name: string, data: string, crc: int, packed: int, unpacked: int, hostOs: int, method: int, isDir: bool}>,
     *   broken: bool,
     *   solid: bool,
     *   comment: string
     * }|array{ok: false, message: string}
     */
    public static function readArchiveBytes(string $data): array
    {
        if (!str_starts_with($data, self::MARKER) && !str_starts_with($data, "Rar!\x1a\x07\x01\x00")) {
            return ['ok' => false, 'message' => 'Not a RAR archive'];
        }
        if (str_starts_with($data, "Rar!\x1a\x07\x01\x00")) {
            return ['ok' => false, 'message' => 'RAR5 archives are not supported in this build'];
        }

        $offset = strlen(self::MARKER);
        $len = strlen($data);
        $entries = [];
        $solid = false;
        $broken = false;
        $comment = '';

        while ($offset + 7 <= $len) {
            $headCrc = self::u16($data, $offset);
            $type = ord($data[$offset + 2]);
            $flags = self::u16($data, $offset + 3);
            $headSize = self::u16($data, $offset + 5);
            if ($headSize < 7 || $offset + $headSize > $len) {
                $broken = true;
                break;
            }
            $hdrBody = substr($data, $offset + 2, $headSize - 2);
            if ((self::crc16($hdrBody) & 0xffff) !== $headCrc) {
                $broken = true;
                break;
            }

            if (self::HEAD_ARCHIVE === $type) {
                $solid = (0 !== ($flags & 0x0008));
                $offset += $headSize;
                continue;
            }

            if (self::HEAD_END === $type) {
                break;
            }

            if (self::HEAD_FILE !== $type) {
                $skip = $headSize;
                if (0 !== ($flags & self::FLAG_ADD_SIZE) && $offset + 11 <= $len) {
                    $skip += self::u32($data, $offset + 7);
                }
                $offset += $skip;
                continue;
            }

            $pos = $offset + 7;
            $addSize = 0;
            if (0 !== ($flags & self::FLAG_ADD_SIZE)) {
                $addSize = self::u32($data, $pos);
                $pos += 4;
            }
            if ($pos + 25 > $offset + $headSize) {
                $broken = true;
                break;
            }
            $packSize = self::u32($data, $pos);
            $unpSize = self::u32($data, $pos + 4);
            $hostOs = ord($data[$pos + 8]);
            $fileCrc = self::u32($data, $pos + 9);
            // ftime at +13 (unused)
            $method = ord($data[$pos + 18]);
            $nameSize = self::u16($data, $pos + 19);
            $attr = self::u32($data, $pos + 21);
            $pos += 25;
            if ($pos + $nameSize > $offset + $headSize) {
                $broken = true;
                break;
            }
            $name = substr($data, $pos, $nameSize);
            $dataOffset = $offset + $headSize;
            $packed = 0 !== $addSize ? $addSize : $packSize;
            if ($dataOffset + $packed > $len) {
                $broken = true;
                break;
            }
            $payload = substr($data, $dataOffset, $packed);
            $isDir = (0 !== ($attr & 0x10)) || str_ends_with($name, '/') || str_ends_with($name, '\\');
            if (!$isDir) {
                if (self::METHOD_STORE !== $method) {
                    return ['ok' => false, 'message' => 'Compressed RAR entries are not supported in this build'];
                }
                if (0 !== ($flags & 0x04)) {
                    return ['ok' => false, 'message' => 'Encrypted RAR entries are not supported in this build'];
                }
                if ((self::crc32u($payload) & 0xffffffff) !== ($fileCrc & 0xffffffff)) {
                    $broken = true;
                }
            }
            $entries[] = [
                'name' => str_replace('\\', '/', $name),
                'data' => $isDir ? '' : $payload,
                'crc' => $fileCrc & 0xffffffff,
                'packed' => $packed,
                'unpacked' => $unpSize,
                'hostOs' => $hostOs,
                'method' => $method,
                'isDir' => $isDir,
            ];
            $offset = $dataOffset + $packed;
        }

        return [
            'ok' => true,
            'entries' => $entries,
            'broken' => $broken,
            'solid' => $solid,
            'comment' => $comment,
        ];
    }

    /**
     * Build a minimal store-method RAR 1.5 archive (fixture / roundtrip).
     *
     * @param array<string, string> $files name => contents
     */
    public static function buildStoreArchive(array $files): string
    {
        $out = self::MARKER;
        $ahBody = chr(self::HEAD_ARCHIVE)."\x00\x00".self::packU16(13)."\x00\x00"."\x00\x00\x00\x00";
        $out .= self::packU16(self::crc16($ahBody)).$ahBody;

        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', (string) $name);
            $content = (string) $content;
            $nameSize = strlen($name);
            $packSize = strlen($content);
            $fileCrc = self::crc32u($content);
            $flags = self::FLAG_ADD_SIZE;
            $headSize = 2 + 1 + 2 + 2 + 4 + 4 + 4 + 1 + 4 + 4 + 1 + 1 + 2 + 4 + $nameSize;
            $fixed = self::packU32($packSize)
                .self::packU32($packSize)
                .self::packU32($packSize)
                .chr(self::HOST_UNIX)
                .self::packU32($fileCrc)
                .self::packU32(0)
                .chr(20)
                .chr(self::METHOD_STORE)
                .self::packU16($nameSize)
                .self::packU32(0x81a4)
                .$name;
            $hdrBody = chr(self::HEAD_FILE).self::packU16($flags).self::packU16($headSize).$fixed;
            $out .= self::packU16(self::crc16($hdrBody)).$hdrBody.$content;
        }

        $ehBody = chr(self::HEAD_END)."\x00\x00".self::packU16(7);
        $out .= self::packU16(self::crc16($ehBody)).$ehBody;

        return $out;
    }

    public static function writeStoreArchive(string $path, array $files): bool
    {
        $bytes = self::buildStoreArchive($files);
        $n = VmFsWriteNative::write($path, $bytes);

        return false !== $n;
    }

    private static function crc16(string $data): int
    {
        return crc32($data) & 0xffff;
    }

    private static function crc32u(string $data): int
    {
        return crc32($data) & 0xffffffff;
    }

    private static function u16(string $data, int $offset): int
    {
        $u = unpack('v', substr($data, $offset, 2));

        return (int) ($u[1] ?? 0);
    }

    private static function u32(string $data, int $offset): int
    {
        $u = unpack('V', substr($data, $offset, 4));

        return (int) ($u[1] ?? 0);
    }

    private static function packU16(int $n): string
    {
        return pack('v', $n & 0xffff);
    }

    private static function packU32(int $n): string
    {
        return pack('V', $n & 0xffffffff);
    }
}
