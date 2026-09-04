<?php
class Uri {
    private const CHAR_GEN_DELIMS = ':\/\?#\[\]@';
    private const CHAR_SUB_DELIMS = '!\$&\'\(\)\*\+,;=';
    private $userInfo = '';
    public function withUserInfo($user, $password = null) {
        if (!is_string($user)) {
            throw new \InvalidArgumentException('User must be a string');
        }
        $info = preg_replace_callback('/[' . self::CHAR_GEN_DELIMS . self::CHAR_SUB_DELIMS . ']++/', [__CLASS__, 'rawurlencodeMatchZero'], $user);
        if (null !== $password && '' !== $password) {
            if (!is_string($password)) {
                throw new \InvalidArgumentException('Password must be a string');
            }
            $info .= ':' . preg_replace_callback('/[' . self::CHAR_GEN_DELIMS . self::CHAR_SUB_DELIMS . ']++/', [__CLASS__, 'rawurlencodeMatchZero'], $password);
        }
        if ($this->userInfo === $info) {
            return $this;
        }
        $new = clone $this;
        $new->userInfo = $info;
        return $new;
    }
    private static function rawurlencodeMatchZero(array $match): string {
        return rawurlencode($match[0]);
    }
    public function getUserInfo() { return $this->userInfo; }
}
$u = new Uri();
$u2 = $u->withUserInfo('a b:c', 'x');
echo $u2->getUserInfo(), "\n";
