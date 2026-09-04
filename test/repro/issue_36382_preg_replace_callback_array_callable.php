<?php
/**
 * #36382 — preg_replace_callback([__CLASS__, 'method'], …) AOT (Nyholm Uri.php).
 * php-src: ext/pcre/php_pcre.c PHP_FUNCTION(preg_replace_callback)
 */
class Uri {
    private static function rawurlencodeMatchZero(array $match): string {
        return rawurlencode($match[0]);
    }
    public static function enc(string $s): string {
        return preg_replace_callback('/[ :]/', [__CLASS__, 'rawurlencodeMatchZero'], $s);
    }
}
echo Uri::enc('a b:c'), "\n";
