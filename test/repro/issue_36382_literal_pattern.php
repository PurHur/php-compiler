<?php
declare(strict_types=1);
class Uri {
    public function withUserInfo($user) {
        return preg_replace_callback('/[ :@]/', [__CLASS__, 'rawurlencodeMatchZero'], $user);
    }
    private static function rawurlencodeMatchZero(array $match): string {
        return rawurlencode($match[0]);
    }
}
echo (new Uri())->withUserInfo('a b:c'), "\n";
