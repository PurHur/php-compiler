<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

/**
 * Parsed php_url snapshot carried by Soap\Url (php-src soap_url_object->url; #23926).
 *
 * Opaque to userland; PHP-in-PHP store keyed by ObjectEntry id in {@see VmSoapOpaque}.
 * Used for same-host keep-alive compare (scheme/host/port) before reusing httpsocket.
 */
final class SoapUrlPayload
{
    public ?string $scheme = null;

    public ?string $host = null;

    /** Effective port — defaults to 80 (http) / 443 (https) when omitted (php-src php_http.c). */
    public int $port = 0;

    public ?string $path = null;

    public ?string $query = null;

    public ?string $fragment = null;

    public ?string $user = null;

    public ?string $pass = null;

    /**
     * Parse an http(s) location into a php_url-shaped payload (php-src php_url_parse).
     */
    public static function fromLocation(string $location): ?self
    {
        $parts = \parse_url($location);
        if (!\is_array($parts) || !isset($parts['host']) || !\is_string($parts['host']) || '' === $parts['host']) {
            return null;
        }
        $scheme = isset($parts['scheme']) && \is_string($parts['scheme'])
            ? \strtolower($parts['scheme'])
            : null;
        if (null === $scheme || ('http' !== $scheme && 'https' !== $scheme)) {
            return null;
        }

        $payload = new self();
        $payload->scheme = $scheme;
        $payload->host = $parts['host'];
        $payload->port = isset($parts['port']) ? (int) $parts['port'] : 0;
        if (0 === $payload->port) {
            // php-src php_http.c: phpurl->port = use_ssl ? 443 : 80
            $payload->port = 'https' === $scheme ? 443 : 80;
        }
        if (isset($parts['path']) && \is_string($parts['path'])) {
            $payload->path = $parts['path'];
        }
        if (isset($parts['query']) && \is_string($parts['query'])) {
            $payload->query = $parts['query'];
        }
        if (isset($parts['fragment']) && \is_string($parts['fragment'])) {
            $payload->fragment = $parts['fragment'];
        }
        if (isset($parts['user']) && \is_string($parts['user'])) {
            $payload->user = $parts['user'];
        }
        if (isset($parts['pass']) && \is_string($parts['pass'])) {
            $payload->pass = $parts['pass'];
        }

        return $payload;
    }

    /** Same-host keep-alive predicate (php-src php_http.c orig vs phpurl compare). */
    public function matchesHost(self $other): bool
    {
        return null !== $this->scheme
            && null !== $this->host
            && $this->scheme === $other->scheme
            && $this->host === $other->host
            && $this->port === $other->port;
    }
}
