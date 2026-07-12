<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uploadprogress;

/**
 * uploadprogress state + PECL file-template lookup (ext/uploadprogress/uploadprogress.c; #6386).
 *
 * Phase 1: register builtins and read progress sidecar files when present; no RFC1867 hook yet.
 */
final class VmUploadprogress
{
    private const DEFAULT_FILENAME_TEMPLATE = '/tmp/upt_%s.txt';

    private const DEFAULT_CONTENTS_TEMPLATE = '/tmp/upload_contents_%s';

    /** @var array<string, array<string, string>> identifier => progress fields */
    private static array $progressById = [];

    public static function reset(): void
    {
        self::$progressById = [];
    }

    /**
     * Test / web-runtime hook: register in-memory progress for an upload identifier.
     *
     * @param array<string, string> $fields
     */
    public static function registerProgress(string $identifier, array $fields): void
    {
        self::$progressById[$identifier] = $fields;
    }

    /**
     * @return array<string, string>|null
     */
    public static function getInfo(string $identifier): ?array
    {
        if (isset(self::$progressById[$identifier])) {
            return self::$progressById[$identifier];
        }

        $template = self::filenameTemplate();
        if ('' === $template) {
            return null;
        }

        $path = self::mkFilename($identifier, $template);
        if (!is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if (false === $contents || '' === $contents) {
            return null;
        }

        $info = [];
        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            if ('' === $line) {
                continue;
            }
            $eq = strpos($line, '=');
            if (false === $eq) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if ('' !== $key) {
                $info[$key] = $value;
            }
        }

        return [] !== $info ? $info : null;
    }

    public static function getContentsEnabled(): bool
    {
        $raw = ini_get('uploadprogress.get_contents');
        if (false === $raw) {
            return false;
        }

        return '1' === $raw || 'on' === strtolower($raw) || 'true' === strtolower($raw);
    }

    /**
     * @return string|false empty string when sidecar exists but is empty
     */
    public static function getContents(string $identifier, string $fieldName, int $maxLength): string|false
    {
        $template = self::contentsTemplate();
        if ('' === $template) {
            return false;
        }

        $dataIdentifier = $identifier.'-'.$fieldName;
        $path = self::mkFilename($dataIdentifier, $template);
        if (!is_readable($path)) {
            return false;
        }

        $data = $maxLength >= 0
            ? @file_get_contents($path, false, null, 0, $maxLength)
            : @file_get_contents($path);
        if (false === $data) {
            return false;
        }

        return $data;
    }

    private static function filenameTemplate(): string
    {
        $raw = ini_get('uploadprogress.file.filename_template');
        if (false === $raw || '' === $raw) {
            return self::DEFAULT_FILENAME_TEMPLATE;
        }

        return $raw;
    }

    private static function contentsTemplate(): string
    {
        $raw = ini_get('uploadprogress.file.contents_template');
        if (false === $raw || '' === $raw) {
            return self::DEFAULT_CONTENTS_TEMPLATE;
        }

        return $raw;
    }

    private static function mkFilename(string $identifier, string $template): string
    {
        $pos = strpos($template, '%s');
        if (false === $pos) {
            return rtrim($template, '/').'/'.$identifier;
        }

        return substr($template, 0, $pos).$identifier.substr($template, $pos + 2);
    }
}
