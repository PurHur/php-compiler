<?php

declare(strict_types=1);

/**
 * #36382 — Nyholm Uri constructor calls parse_url(); NestedJIT ParseUrlJitHelper
 * must match Zend for runtime URL strings. withUserInfo uses clone + return;
 * RETURN must addref before freeDeadVariables so props survive the call.
 */
class Uri {
    private $scheme = '';
    private $host = '';
    private $path = '';
    private $userInfo = '';

    public function __construct(string $uri = '') {
        if ('' === $uri) {
            return;
        }
        $parts = parse_url($uri);
        if (false === $parts) {
            throw new InvalidArgumentException('Unable to parse URI');
        }
        $this->scheme = $parts['scheme'] ?? '';
        $this->host = $parts['host'] ?? '';
        $this->path = $parts['path'] ?? '';
        if (isset($parts['user'])) {
            $this->userInfo = $parts['user'];
            if (isset($parts['pass'])) {
                $this->userInfo .= ':' . $parts['pass'];
            }
        }
    }

    public function withUserInfo($user, $password = null) {
        $info = preg_replace_callback('/[ :@]/', [__CLASS__, 'rawurlencodeMatchZero'], $user);
        if (null !== $password && '' !== $password) {
            $info .= ':' . preg_replace_callback('/[ :@]/', [__CLASS__, 'rawurlencodeMatchZero'], $password);
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
    public function getHost() { return $this->host; }
}

$u = new Uri('https://user:pass@example.com/path');
$u2 = $u->withUserInfo('a b:c', 'x');
echo $u->getHost(), '|', $u2->getUserInfo(), "\n";
