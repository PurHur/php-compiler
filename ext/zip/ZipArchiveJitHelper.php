<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

/**
 * Filesystem-backed ZipArchive NestedJIT helpers (#35424).
 *
 * Thin AOT NestedJIT does not share private statics across methods, so session
 * state lives in a temp file keyed by handle id (stored on the object as __zipId).
 *
 * php-src: ext/zip/php_zip.c — zim_ZipArchive_open / addFromString / close / getFromName
 */
final class ZipArchiveJitHelper
{
    private const MAGIC = "PZ1\n";

    private static function sessionPath(int $id): string
    {
        return sys_get_temp_dir().'/phpc_zip_sess_'.$id.'.dat';
    }

    public static function alloc(): int
    {
        $seq = sys_get_temp_dir().'/phpc_zip_alloc_seq';
        $id = 1;
        if (file_exists($seq)) {
            $raw = file_get_contents($seq);
            if (false !== $raw) {
                $id = ((int) $raw) + 1;
            }
        }
        if ($id <= 0) {
            $id = 1;
        }
        file_put_contents($seq, (string) $id);
        file_put_contents(self::sessionPath($id), self::emptyBlob());

        return $id;
    }

    public static function emptyBlob(): string
    {
        return '0,0,-1,0,0,0,,0,';
    }

    public static function open(int $id, string $filename, int $flags): int
    {
        $blob = self::loadSession($id);
        $s = self::parse($blob);
        if (1 === $s['open']) {
            return 0;
        }
        $exists = ('' !== $filename) && file_exists($filename);
        if ($exists && 0 !== ($flags & 2)) {
            return 0;
        }
        $entries = [];
        if (!$exists) {
            if (0 === ($flags & 1)) {
                return 0;
            }
        } elseif (0 === ($flags & 8)) {
            $raw = file_get_contents($filename);
            if (false === $raw || strlen($raw) < 4 || substr($raw, 0, 4) !== self::MAGIC) {
                return 0;
            }
            $entries = self::parse(substr($raw, 4))['entries'];
        }
        $s['entries'] = $entries;
        $s['filename'] = $filename;
        $s['open'] = 1;
        $s['dirty'] = 0;
        $s['status'] = 0;
        $s['lastId'] = -1;
        self::saveSession($id, self::format($s));

        return 1;
    }

    public static function close(int $id): int
    {
        $s = self::parse(self::loadSession($id));
        if (1 !== $s['open']) {
            return 0;
        }
        $disk = self::MAGIC.self::format([
            'status' => 0,
            'statusSys' => 0,
            'lastId' => $s['lastId'],
            'open' => 0,
            'dirty' => 0,
            'filename' => '',
            'entries' => $s['entries'],
        ]);
        if (false === file_put_contents($s['filename'], $disk)) {
            return 0;
        }
        $s['open'] = 0;
        $s['entries'] = [];
        $s['filename'] = '';
        $s['status'] = 0;
        self::saveSession($id, self::format($s));

        return 1;
    }

    public static function addFromString(int $id, string $name, string $content): int
    {
        $s = self::parse(self::loadSession($id));
        if (1 !== $s['open']) {
            return 0;
        }
        $replaced = false;
        foreach ($s['entries'] as $i => $e) {
            if ($e['name'] === $name) {
                $s['entries'][$i] = ['name' => $name, 'data' => $content];
                $s['lastId'] = $i;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $s['entries'][] = ['name' => $name, 'data' => $content];
            $s['lastId'] = \count($s['entries']) - 1;
        }
        $s['dirty'] = 1;
        $s['status'] = 0;
        self::saveSession($id, self::format($s));

        return 1;
    }

    public static function getFromNameFound(int $id, string $name): int
    {
        $s = self::parse(self::loadSession($id));
        if (1 !== $s['open']) {
            return 0;
        }
        foreach ($s['entries'] as $e) {
            if ($e['name'] === $name) {
                return 1;
            }
        }

        return 0;
    }

    public static function getFromNameData(int $id, string $name): string
    {
        $s = self::parse(self::loadSession($id));
        if (1 !== $s['open']) {
            return '';
        }
        foreach ($s['entries'] as $e) {
            if ($e['name'] === $name) {
                return $e['data'];
            }
        }

        return '';
    }

    public static function propNumFiles(int $id): int
    {
        return \count(self::parse(self::loadSession($id))['entries']);
    }

    public static function propStatus(int $id): int
    {
        return self::parse(self::loadSession($id))['status'];
    }

    public static function propStatusSys(int $id): int
    {
        return 0;
    }

    public static function propLastId(int $id): int
    {
        return self::parse(self::loadSession($id))['lastId'];
    }

    private static function loadSession(int $id): string
    {
        $path = self::sessionPath($id);
        if (!file_exists($path)) {
            return self::emptyBlob();
        }
        $raw = file_get_contents($path);

        return false === $raw ? self::emptyBlob() : $raw;
    }

    private static function saveSession(int $id, string $blob): void
    {
        file_put_contents(self::sessionPath($id), $blob);
    }

    /** @return array{status: int, statusSys: int, lastId: int, open: int, dirty: int, filename: string, entries: list<array{name: string, data: string}>} */
    private static function parse(string $blob): array
    {
        if ('' === $blob) {
            return ['status' => 0, 'statusSys' => 0, 'lastId' => -1, 'open' => 0, 'dirty' => 0, 'filename' => '', 'entries' => []];
        }
        $parts = explode(',', $blob, 7);
        if (\count($parts) < 7) {
            return ['status' => 0, 'statusSys' => 0, 'lastId' => -1, 'open' => 0, 'dirty' => 0, 'filename' => '', 'entries' => []];
        }
        $fnLen = (int) $parts[5];
        $rest = $parts[6];
        $filename = substr($rest, 0, $fnLen);
        $rest = substr($rest, $fnLen);
        $entries = [];
        if ('' !== $rest && ',' === $rest[0]) {
            $rest = substr($rest, 1);
            $parts2 = explode(',', $rest, 2);
            $n = (int) $parts2[0];
            $rest = $parts2[1] ?? '';
            for ($i = 0; $i < $n; ++$i) {
                $p = explode(',', $rest, 2);
                $nameLen = (int) $p[0];
                $rest = $p[1] ?? '';
                $name = substr($rest, 0, $nameLen);
                $rest = substr($rest, $nameLen);
                if ('' !== $rest && ',' === $rest[0]) {
                    $rest = substr($rest, 1);
                }
                $p = explode(',', $rest, 2);
                $dataLen = (int) $p[0];
                $rest = $p[1] ?? '';
                $data = substr($rest, 0, $dataLen);
                $rest = substr($rest, $dataLen);
                if ('' !== $rest && ',' === $rest[0]) {
                    $rest = substr($rest, 1);
                }
                $entries[] = ['name' => $name, 'data' => $data];
            }
        }

        return [
            'status' => (int) $parts[0],
            'statusSys' => (int) $parts[1],
            'lastId' => (int) $parts[2],
            'open' => (int) $parts[3],
            'dirty' => (int) $parts[4],
            'filename' => $filename,
            'entries' => $entries,
        ];
    }

    /** @param array{status: int, statusSys: int, lastId: int, open: int, dirty: int, filename: string, entries: list<array{name: string, data: string}>} $s */
    private static function format(array $s): string
    {
        $out = $s['status'].','.$s['statusSys'].','.$s['lastId'].','
            .$s['open'].','.$s['dirty'].','.strlen($s['filename']).','
            .$s['filename'].','.\count($s['entries']).',';
        foreach ($s['entries'] as $e) {
            $out .= strlen($e['name']).','.$e['name'].','.strlen($e['data']).','.$e['data'].',';
        }

        return $out;
    }
}
