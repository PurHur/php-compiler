<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_send_mail() language mail profiles (php-src ext/mbstring/libmbfl/nls; #6548).
 */
final class MbstringMailProfile
{
    /**
     * @return array{charset: string, header: string, body: string}
     */
    public static function forLanguage(string $language): array
    {
        return match ($language) {
            'Japanese' => ['charset' => 'ISO-2022-JP', 'header' => 'base64', 'body' => '7bit'],
            'English', 'German' => ['charset' => 'ISO-8859-1', 'header' => 'base64', 'body' => '7bit'],
            'Korean' => ['charset' => 'EUC-KR', 'header' => 'base64', 'body' => '7bit'],
            'Russian' => ['charset' => 'KOI8-R', 'header' => 'base64', 'body' => '7bit'],
            'Simplified Chinese' => ['charset' => 'GB2312', 'header' => 'base64', 'body' => '7bit'],
            'Traditional Chinese' => ['charset' => 'BIG5', 'header' => 'base64', 'body' => '7bit'],
            default => ['charset' => 'UTF-8', 'header' => 'base64', 'body' => 'base64'],
        };
    }
}
