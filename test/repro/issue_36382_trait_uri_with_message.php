<?php
/**
 * #36382 — MessageTrait + RequestTrait property layout: $uri must stay object.
 */

interface UriLike36382b
{
    public function getHost(): string;
    public function getPort(): ?int;
}

final class UriObj36382b implements UriLike36382b
{
    public function getHost(): string { return ''; }
    public function getPort(): ?int { return null; }
}

trait MessageTrait36382b
{
    /** @var array */
    private $headers = [];
    /** @var array */
    private $headerNames = [];
    /** @var string */
    private $protocol = '1.1';
    /** @var object|null */
    private $stream;

    public function hasHeader($header): bool
    {
        return isset($this->headerNames[strtr($header, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')]);
    }

    private function setHeaders(array $headers): void
    {
        foreach ($headers as $header => $value) {
            $this->headers[$header] = $value;
        }
    }
}

trait RequestTrait36382b
{
    /** @var string */
    private $method;
    /** @var string|null */
    private $requestTarget;
    /** @var UriLike36382b|null */
    private $uri;

    private function updateHostFromUri(): void
    {
        $uri = $this->uri;
        echo 'uri_type=', is_object($uri) ? get_class($uri) : gettype($uri), "\n";
        if (!is_object($uri)) {
            echo "FAIL_NOT_OBJECT\n";
            return;
        }
        if ('' === $host = $uri->getHost()) {
            echo "empty_host_ok\n";
            return;
        }
        echo "host=$host\n";
    }
}

final class Request36382b
{
    use MessageTrait36382b;
    use RequestTrait36382b;

    public function __construct(string $method, UriLike36382b $uri, array $headers = [], $body = null, string $version = '1.1')
    {
        $this->method = $method;
        $this->uri = $uri;
        $this->setHeaders($headers);
        $this->protocol = $version;
        if (!$this->hasHeader('Host')) {
            $this->updateHostFromUri();
        }
    }

    public function getMethod(): string { return $this->method; }
}

$r = new Request36382b('GET', new UriObj36382b());
echo 'method=', $r->getMethod(), "\n";
echo "ok\n";
