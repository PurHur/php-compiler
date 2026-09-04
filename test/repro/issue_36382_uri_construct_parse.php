<?php
/**
 * #36382 — Nyholm Uri::__construct with parse_url / isset / null-coalesce (Slim AOT verify).
 */
final class UriMini
{
    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ('' !== $uri) {
            if (false === $parts = \parse_url($uri)) {
                throw new \InvalidArgumentException(\sprintf('Unable to parse URI: "%s"', $uri));
            }
            $this->scheme = isset($parts['scheme']) ? \strtr($parts['scheme'], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') : '';
            $this->userInfo = $parts['user'] ?? '';
            $this->host = isset($parts['host']) ? \strtr($parts['host'], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') : '';
            $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
            $this->path = isset($parts['path']) ? (string) $parts['path'] : '';
            $this->query = isset($parts['query']) ? (string) $parts['query'] : '';
            $this->fragment = isset($parts['fragment']) ? (string) $parts['fragment'] : '';
            if (isset($parts['pass'])) {
                $this->userInfo .= ':' . $parts['pass'];
            }
        }
    }

    public function host(): string
    {
        return $this->host;
    }
}

$u = new UriMini('https://User:Pass@Example.COM:8080/a/b?x=1#frag');
echo $u->host(), "\n";
