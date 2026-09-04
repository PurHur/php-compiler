<?php
/**
 * #36382 — Nyholm Uri.php method order: encode before rawurlencodeMatchZero declaration.
 * php-src: ext/pcre/php_pcre.c PHP_FUNCTION(preg_replace_callback)
 */
class Uri
{
    private const CHAR_GEN_DELIMS = ':\/\?#\[\]@';

    private const CHAR_SUB_DELIMS = '!\$&\'\(\)\*\+,;=';

    public static function enc(string $s): string
    {
        return preg_replace_callback(
            '/['.self::CHAR_GEN_DELIMS.self::CHAR_SUB_DELIMS.']++/',
            [__CLASS__, 'rawurlencodeMatchZero'],
            $s
        );
    }

    private static function rawurlencodeMatchZero(array $match): string
    {
        return rawurlencode($match[0]);
    }
}
echo Uri::enc('a b:c'), "\n";
