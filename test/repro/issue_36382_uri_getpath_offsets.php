<?php
/**
 * #36382 — Nyholm Uri::getPath() after createUri('/hello') (Slim route path).
 * Repro: string offset on typed $path after parse_url + filterPath.
 */
final class UriPathMini
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
            $this->scheme = isset($parts['scheme']) ? (string) $parts['scheme'] : '';
            $this->host = isset($parts['host']) ? (string) $parts['host'] : '';
            $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
            $this->path = isset($parts['path']) ? (string) $parts['path'] : '';
            $this->query = isset($parts['query']) ? (string) $parts['query'] : '';
            $this->fragment = isset($parts['fragment']) ? (string) $parts['fragment'] : '';
        }
    }

    public function getPath(): string
    {
        $path = $this->path;

        if ('' !== $path && '/' !== $path[0]) {
            if ('' !== $this->host) {
                $path = '/' . $path;
            }
        } elseif (isset($path[1]) && '/' === $path[1]) {
            $path = '/' . \ltrim($path, '/');
        }

        return $path;
    }
}

$u = new UriPathMini('/hello');
echo $u->getPath(), "\n";
$u2 = new UriPathMini('');
echo 'empty:[', $u2->getPath(), "]\n";
$u3 = new UriPathMini('//evil');
echo $u3->getPath(), "\n";
