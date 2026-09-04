<?php
declare(strict_types=1);
/**
 * #36382 — Nyholm withUserInfo uses computed char-class pattern (const concat).
 * Must take UriRawurlencodeReplaceJitHelper without NestedJIT-stalling PregAotFastPath.
 */
class Uri {
    private const CHAR_SUB_DELIMS = '!\$&\'\(\)\*\+,;=';
    private const CHAR_GEN_DELIMS = ':\/\?#\[\]@';
    private $userInfo = '';
    public function withUserInfo($user, $password = null) {
        $info = preg_replace_callback('/[' . self::CHAR_GEN_DELIMS . self::CHAR_SUB_DELIMS . ']++/', [__CLASS__, 'rawurlencodeMatchZero'], $user);
        if (null !== $password && '' !== $password) {
            $info .= ':' . preg_replace_callback('/[' . self::CHAR_GEN_DELIMS . self::CHAR_SUB_DELIMS . ']++/', [__CLASS__, 'rawurlencodeMatchZero'], $password);
        }
        $this->userInfo = $info;
        return $this;
    }
    private static function rawurlencodeMatchZero(array $match): string {
        return rawurlencode($match[0]);
    }
    public function getUserInfo() { return $this->userInfo; }
}
$u = (new Uri())->withUserInfo('a b:c', 'x');
echo $u->getUserInfo(), "\n";
