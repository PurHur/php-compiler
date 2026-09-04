<?php
declare(strict_types=1);
// Force early NestedJIT of Uri helper via a single preg_replace_callback with rawurlencodeMatchZero.
class H {
    public static function run($s) {
        return preg_replace_callback('/[ :@]/', [__CLASS__, 'rawurlencodeMatchZero'], $s);
    }
    private static function rawurlencodeMatchZero(array $match): string {
        return rawurlencode($match[0]);
    }
}
echo H::run('a b:c'), "\n";
