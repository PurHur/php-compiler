<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;

/**
 * mbstring encoding name registry (php-src ext/mbstring/mbfl_encoding; #13100).
 */
final class MbstringEncodingRegistry
{
    /** @var array<string, array{canonical: string, mime: string, aliases: list<string>}> */
    private const ENCODINGS = [
        'UTF-8' => [
            'canonical' => 'UTF-8',
            'mime' => 'UTF-8',
            'aliases' => ['utf8'],
        ],
        'ASCII' => [
            'canonical' => 'ASCII',
            'mime' => 'US-ASCII',
            'aliases' => [
                'ANSI_X3.4-1968', 'iso-ir-6', 'ANSI_X3.4-1986', 'ISO_646.irv:1991',
                'US-ASCII', 'ISO646-US', 'us', 'IBM367', 'IBM-367', 'cp367', 'csASCII',
            ],
        ],
        'ISO-8859-1' => [
            'canonical' => 'ISO-8859-1',
            'mime' => 'ISO-8859-1',
            'aliases' => ['latin1', 'LATIN1', 'ISO8859-1', 'ISO88591'],
        ],
        'SJIS' => [
            'canonical' => 'SJIS',
            'mime' => 'Shift_JIS',
            'aliases' => ['x-sjis', 'SHIFT-JIS'],
        ],
        'EUC-JP' => [
            'canonical' => 'EUC-JP',
            'mime' => 'EUC-JP',
            'aliases' => ['EUC', 'EUC_JP', 'eucJP', 'x-euc-jp'],
        ],
        'ISO-2022-JP' => [
            'canonical' => 'ISO-2022-JP',
            'mime' => 'ISO-2022-JP',
            'aliases' => [],
        ],
        'CP932' => [
            'canonical' => 'CP932',
            'mime' => 'Shift_JIS',
            'aliases' => ['MS932', 'Windows-31J', 'MS_Kanji'],
        ],
        '8BIT' => [
            'canonical' => '8BIT',
            'mime' => '8bit',
            'aliases' => ['binary'],
        ],
        // libmbfl transfer / pseudo encodings (php-src mbfilter_{base64,uuencode,qprint,htmlent}.c; #28983).
        'BASE64' => [
            'canonical' => 'BASE64',
            'mime' => 'BASE64',
            'aliases' => [],
        ],
        'UUENCODE' => [
            'canonical' => 'UUENCODE',
            'mime' => 'x-uuencode',
            'aliases' => [],
        ],
        'Quoted-Printable' => [
            'canonical' => 'Quoted-Printable',
            'mime' => 'Quoted-Printable',
            'aliases' => ['qprint'],
        ],
        'HTML-ENTITIES' => [
            'canonical' => 'HTML-ENTITIES',
            'mime' => 'HTML-ENTITIES',
            'aliases' => ['HTML', 'html'],
        ],
    ];

    /**
     * Transfer encodings whose php_mb_get_encoding() path emits E_DEPRECATED on PHP 8.2+
     * (php-src ext/mbstring/mbstring.c; #28983).
     *
     * @return list<string>
     */
    public static function specialTransferEncodings(): array
    {
        return ['BASE64', 'UUENCODE', 'Quoted-Printable', 'HTML-ENTITIES'];
    }

    public static function isSpecialTransferEncoding(string $canonical): bool
    {
        return \in_array($canonical, self::specialTransferEncodings(), true);
    }

    public static function resolve(string $name): ?string
    {
        $trimmed = trim($name);
        if ('' === $trimmed) {
            return null;
        }

        $canonical = CharsetEngine::canonicalize($trimmed);
        if (null !== $canonical && isset(self::ENCODINGS[$canonical])) {
            return $canonical;
        }

        $upper = strtoupper(str_replace(['-', '_', ' '], '', $trimmed));
        foreach (self::ENCODINGS as $key => $meta) {
            if ($upper === strtoupper(str_replace(['-', '_', ' '], '', $key))) {
                return $key;
            }
            foreach ($meta['aliases'] as $alias) {
                if ($upper === strtoupper(str_replace(['-', '_', ' ', ':'], '', $alias))) {
                    return $key;
                }
            }
        }

        return null;
    }

    public static function isValid(string $name): bool
    {
        return null !== self::resolve($name);
    }

    public static function assertValid(string $name, string $function, int $argIndex): string
    {
        $canonical = self::resolve($name);
        if (null === $canonical) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($encoding) must be a valid encoding, "%s" given',
                $function,
                $argIndex + 1,
                $name
            ));
        }

        return $canonical;
    }

    public static function preferredMimeName(string $canonical): string|false
    {
        $meta = self::ENCODINGS[$canonical] ?? null;
        if (null === $meta || '' === $meta['mime']) {
            return false;
        }

        return $meta['mime'];
    }

    /**
     * @return list<string>
     */
    public static function aliases(string $canonical): array
    {
        return self::ENCODINGS[$canonical]['aliases'] ?? [];
    }

    /**
     * php_mb_list_encodings() table (php-src ext/mbstring/mbfl_encoding.c; #15448).
     *
     * @return list<string>
     */
    public static function listEncodings(): array
    {
        return [
            'BASE64',
            'UUENCODE',
            'HTML-ENTITIES',
            'Quoted-Printable',
            '7bit',
            '8bit',
            'UCS-4',
            'UCS-4BE',
            'UCS-4LE',
            'UCS-2',
            'UCS-2BE',
            'UCS-2LE',
            'UTF-32',
            'UTF-32BE',
            'UTF-32LE',
            'UTF-16',
            'UTF-16BE',
            'UTF-16LE',
            'UTF-8',
            'UTF-7',
            'UTF7-IMAP',
            'ASCII',
            'EUC-JP',
            'SJIS',
            'eucJP-win',
            'EUC-JP-2004',
            'SJIS-Mobile#DOCOMO',
            'SJIS-Mobile#KDDI',
            'SJIS-Mobile#SOFTBANK',
            'SJIS-mac',
            'SJIS-2004',
            'UTF-8-Mobile#DOCOMO',
            'UTF-8-Mobile#KDDI-A',
            'UTF-8-Mobile#KDDI-B',
            'UTF-8-Mobile#SOFTBANK',
            'CP932',
            'SJIS-win',
            'CP51932',
            'JIS',
            'ISO-2022-JP',
            'ISO-2022-JP-MS',
            'GB18030',
            'Windows-1252',
            'Windows-1254',
            'ISO-8859-1',
            'ISO-8859-2',
            'ISO-8859-3',
            'ISO-8859-4',
            'ISO-8859-5',
            'ISO-8859-6',
            'ISO-8859-7',
            'ISO-8859-8',
            'ISO-8859-9',
            'ISO-8859-10',
            'ISO-8859-13',
            'ISO-8859-14',
            'ISO-8859-15',
            'ISO-8859-16',
            'EUC-CN',
            'CP936',
            'HZ',
            'EUC-TW',
            'BIG-5',
            'CP950',
            'EUC-KR',
            'UHC',
            'ISO-2022-KR',
            'Windows-1251',
            'CP866',
            'KOI8-R',
            'KOI8-U',
            'ArmSCII-8',
            'CP850',
            'ISO-2022-JP-2004',
            'ISO-2022-JP-MOBILE#KDDI',
            'CP50220',
            'CP50221',
            'CP50222',
        ];
    }

    /**
     * @return list<string>
     */
    public static function parseOrderList(string $function, int $argIndex, string $list): array
    {
        $parts = preg_split('/\s*,\s*/', $list) ?: [];
        $order = [];
        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }
            $canonical = self::resolve($part);
            if (null === $canonical) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #%d ($encoding) contains invalid encoding "%s"',
                    $function,
                    $argIndex + 1,
                    $part
                ));
            }
            $order[] = $canonical;
        }
        self::assertNonEmptyOrder($function, $argIndex, $order);

        return $order;
    }

    /**
     * @param list<string> $order
     */
    public static function assertNonEmptyOrder(string $function, int $argIndex, array $order): void
    {
        if ([] === $order) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($encoding) must specify at least one encoding',
                $function,
                $argIndex + 1
            ));
        }
    }
}
