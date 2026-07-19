<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

/**
 * PHP-in-PHP inifile handler subset (php-src ext/dba/dba_inifile.c + libinifile; #21168).
 *
 * Keys are group + name (NUL-separated internally). On-disk format is a simple INI file.
 */
final class VmDbaInifile
{
    /**
     * @param resource $fp
     *
     * @return list<array{group: string, name: string, value: string}>
     */
    public static function load($fp): array
    {
        \rewind($fp);
        $raw = \stream_get_contents($fp);
        if (false === $raw || '' === $raw) {
            return [];
        }
        $rows = [];
        $group = '';
        foreach (\preg_split("/\r\n|\n|\r/", $raw) as $line) {
            $trim = \trim($line);
            if ('' === $trim || \str_starts_with($trim, ';') || \str_starts_with($trim, '#')) {
                continue;
            }
            if (\str_starts_with($trim, '[') && \str_ends_with($trim, ']')) {
                $group = \substr($trim, 1, -1);

                continue;
            }
            $eq = \strpos($line, '=');
            if (false === $eq) {
                continue;
            }
            $name = \trim(\substr($line, 0, $eq));
            $value = \trim(\substr($line, $eq + 1));
            $rows[] = ['group' => $group, 'name' => $name, 'value' => $value];
        }

        return $rows;
    }

    /**
     * @param resource                                                             $fp
     * @param list<array{group: string, name: string, value: string}> $rows
     */
    public static function save($fp, array $rows): void
    {
        \ftruncate($fp, 0);
        \rewind($fp);
        $currentGroup = null;
        foreach ($rows as $row) {
            if ($currentGroup !== $row['group']) {
                if ('' !== $row['group'] || null !== $currentGroup) {
                    \fwrite($fp, '['.$row['group']."]\n");
                }
                $currentGroup = $row['group'];
            }
            \fwrite($fp, $row['name'].'='.$row['value']."\n");
        }
        \fflush($fp);
    }

    /**
     * Split internal key (group\\0name or bare name) into parts.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitInternalKey(string $key): array
    {
        $nul = \strpos($key, "\0");
        if (false === $nul) {
            return ['', $key];
        }

        return [\substr($key, 0, $nul), \substr($key, $nul + 1)];
    }

    public static function joinInternalKey(string $group, string $name): string
    {
        return '' === $group ? $name : $group."\0".$name;
    }

    public static function displayKey(string $group, string $name): string
    {
        return '' === $group ? $name : '['.$group.']'.$name;
    }

    /**
     * @param resource $fp
     */
    public static function fetch($fp, string $key): ?string
    {
        [$group, $name] = self::splitInternalKey($key);
        foreach (self::load($fp) as $row) {
            if ($row['group'] === $group && $row['name'] === $name) {
                return $row['value'];
            }
        }

        return null;
    }

    /**
     * @param resource $fp
     */
    public static function exists($fp, string $key): bool
    {
        return null !== self::fetch($fp, $key);
    }

    /**
     * @param resource $fp
     */
    public static function insert($fp, string $key, string $value): bool
    {
        if (self::exists($fp, $key)) {
            return false;
        }
        [$group, $name] = self::splitInternalKey($key);
        $rows = self::load($fp);
        $rows[] = ['group' => $group, 'name' => $name, 'value' => $value];
        self::save($fp, $rows);

        return true;
    }

    /**
     * @param resource $fp
     */
    public static function replace($fp, string $key, string $value): bool
    {
        [$group, $name] = self::splitInternalKey($key);
        $rows = self::load($fp);
        $found = false;
        foreach ($rows as $i => $row) {
            if ($row['group'] === $group && $row['name'] === $name) {
                $rows[$i]['value'] = $value;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $rows[] = ['group' => $group, 'name' => $name, 'value' => $value];
        }
        self::save($fp, $rows);

        return true;
    }

    /**
     * @param resource $fp
     */
    public static function delete($fp, string $key): bool
    {
        [$group, $name] = self::splitInternalKey($key);
        $rows = self::load($fp);
        $out = [];
        $deleted = false;
        foreach ($rows as $row) {
            if ($row['group'] === $group && $row['name'] === $name) {
                $deleted = true;

                continue;
            }
            $out[] = $row;
        }
        if (!$deleted) {
            return false;
        }
        self::save($fp, $out);

        return true;
    }

    /**
     * @param resource $fp
     *
     * @return array{0: ?string, 1: int} display key + next index
     */
    public static function firstKey($fp): array
    {
        $rows = self::load($fp);
        if ([] === $rows) {
            return [null, 0];
        }

        return [self::displayKey($rows[0]['group'], $rows[0]['name']), 1];
    }

    /**
     * @param resource $fp
     *
     * @return array{0: ?string, 1: int}
     */
    public static function nextKey($fp, int $index): array
    {
        $rows = self::load($fp);
        if ($index < 0 || $index >= \count($rows)) {
            return [null, 0];
        }

        return [self::displayKey($rows[$index]['group'], $rows[$index]['name']), $index + 1];
    }
}
