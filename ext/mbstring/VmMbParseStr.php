<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\ParseStrEngine;

/**
 * mb_parse_str() core — query parse + HTTP-input→internal charset (php-src ext/mbstring/mb_gpc.c).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_parse_str)
 * php-src: ext/mbstring/mb_gpc.c — _php_mb_encoding_handler_ex
 */
final class VmMbParseStr
{
    /**
     * @return array{ok: bool, params: array<string, mixed>, detected: ?string}
     */
    public static function parse(string $encoded): array
    {
        if ('' === $encoded) {
            return ['ok' => false, 'params' => [], 'detected' => null];
        }

        $fromList = MbstringState::httpInputList();
        $to = MbstringState::internalEncoding();
        $detected = self::resolveFromEncoding($encoded, $fromList);
        $params = ParseStrEngine::parse($encoded);
        if (null !== $detected && 'pass' !== $detected && 'pass' !== $to) {
            $params = self::convertTree($params, $detected, $to);
        }

        return ['ok' => true, 'params' => $params, 'detected' => $detected];
    }

    /**
     * @param list<string> $fromList
     */
    private static function resolveFromEncoding(string $encoded, array $fromList): ?string
    {
        if ([] === $fromList) {
            return 'pass';
        }
        if (1 === \count($fromList)) {
            return $fromList[0];
        }

        $samples = self::collectDecodedSamples($encoded);
        if ([] === $samples) {
            return $fromList[0];
        }
        $blob = implode("\0", $samples);
        $guessed = VmMbstring::detectEncoding($blob, $fromList, false);
        if (false === $guessed) {
            return 'pass';
        }

        return $guessed;
    }

    /**
     * @return list<string>
     */
    private static function collectDecodedSamples(string $encoded): array
    {
        $samples = [];
        foreach (explode('&', $encoded) as $pair) {
            if ('' === $pair) {
                continue;
            }
            $eq = strpos($pair, '=');
            if (false === $eq) {
                $samples[] = self::urlDecode($pair);
                $samples[] = '';
            } else {
                $samples[] = self::urlDecode(substr($pair, 0, $eq));
                $samples[] = self::urlDecode(substr($pair, $eq + 1));
            }
        }

        return $samples;
    }

    private static function urlDecode(string $value): string
    {
        $value = str_replace('+', ' ', $value);
        if (!str_contains($value, '%')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/%[0-9A-Fa-f]{2}/',
            static function (array $m): string {
                return \chr((int) hexdec(substr($m[0], 1)));
            },
            $value
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function convertTree(array $params, string $from, string $to): array
    {
        if (0 === strcasecmp($from, $to)) {
            return $params;
        }
        $out = [];
        foreach ($params as $key => $value) {
            $newKey = $key;
            if (\is_string($key)) {
                $convertedKey = VmMbstring::convertEncoding($key, $to, $from);
                $newKey = false === $convertedKey ? $key : $convertedKey;
            }
            if (\is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$newKey] = self::convertTree($value, $from, $to);
            } elseif (\is_string($value)) {
                $converted = VmMbstring::convertEncoding($value, $to, $from);
                $out[$newKey] = false === $converted ? $value : $converted;
            } else {
                $out[$newKey] = $value;
            }
        }

        return $out;
    }
}
