<?php

declare(strict_types=1);

/**
 * AOT (#36382): Nyholm Uri::__construct + parse_url under IncludeHelper NestedJIT
 * either silent-exits mid-ctor (assoc isset/?? leaves path empty) or SEGVs on the
 * first parse_url call. Fast-path absolute paths without an authority (Slim /hello,
 * REQUEST_URI) with substr — Zend-equivalent for that shape. Full URLs keep the
 * original assoc parse_url body for Zend parity outside NestedJIT-hot paths.
 *
 * php-src: ext/standard/url.c php_url_parse_ex; Zend/zend_vm_def.h ZEND_ISSET_ISEMPTY_DIM.
 *
 * Usage: php script/composer/patch-nyholm-uri-construct-36382.php path/to/Uri.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: {$argv[0]} Uri.php\n");
    exit(1);
}
if ('Uri.php' !== basename($path)) {
    fwrite(STDERR, "expected Uri.php, got ".basename($path)."\n");
    exit(1);
}
$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "read failed: {$path}\n");
    exit(1);
}
if (str_contains($text, 'AOT (#36382): fast-path absolute path')) {
    echo "Uri.php already patched (#36382)\n";
    exit(0);
}

$old = <<<'PHP'
    public function __construct(string $uri = '')
    {
        if ('' !== $uri) {
            if (false === $parts = \parse_url($uri)) {
                throw new \InvalidArgumentException(\sprintf('Unable to parse URI: "%s"', $uri));
            }

            // Apply parse_url parts to a URI.
            $this->scheme = isset($parts['scheme']) ? \strtr($parts['scheme'], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') : '';
            $this->userInfo = $parts['user'] ?? '';
            $this->host = isset($parts['host']) ? \strtr($parts['host'], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') : '';
            $this->port = isset($parts['port']) ? $this->filterPort($parts['port']) : null;
            $this->path = isset($parts['path']) ? $this->filterPath($parts['path']) : '';
            $this->query = isset($parts['query']) ? $this->filterQueryAndFragment($parts['query']) : '';
            $this->fragment = isset($parts['fragment']) ? $this->filterQueryAndFragment($parts['fragment']) : '';
            if (isset($parts['pass'])) {
                $this->userInfo .= ':' . $parts['pass'];
            }
        }
    }
PHP;

$new = <<<'PHP'
    public function __construct(string $uri = '')
    {
        if ('' !== $uri) {
            // AOT (#36382): fast-path absolute path without authority — NestedJIT
            // parse_url assoc/component SEGVs or silent-exits mid-ctor (empty path).
            if (isset($uri[0]) && '/' === $uri[0] && false === \strpos($uri, '://')) {
                $end = \strlen($uri);
                $hash = \strpos($uri, '#');
                if (false !== $hash) {
                    $this->fragment = \substr($uri, $hash + 1);
                    $end = $hash;
                }
                $q = \strpos($uri, '?');
                if (false !== $q && $q < $end) {
                    $this->query = \substr($uri, $q + 1, $end - $q - 1);
                    $end = $q;
                }
                $this->path = \substr($uri, 0, $end);

                return;
            }

            if (false === $parts = \parse_url($uri)) {
                throw new \InvalidArgumentException(\sprintf('Unable to parse URI: "%s"', $uri));
            }

            // Apply parse_url parts to a URI.
            $this->scheme = isset($parts['scheme']) ? \strtr($parts['scheme'], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') : '';
            $this->userInfo = isset($parts['user']) ? $parts['user'] : '';
            $this->host = isset($parts['host']) ? \strtr($parts['host'], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') : '';
            $this->port = isset($parts['port']) ? $this->filterPort($parts['port']) : null;
            $this->path = isset($parts['path']) ? $parts['path'] : '';
            $this->query = isset($parts['query']) ? $parts['query'] : '';
            $this->fragment = isset($parts['fragment']) ? $parts['fragment'] : '';
            if (isset($parts['pass'])) {
                $this->userInfo .= ':' . $parts['pass'];
            }
        }
    }
PHP;

if (!str_contains($text, $old)) {
    fwrite(STDERR, "Uri::__construct pattern not found in {$path}\n");
    exit(1);
}
$patched = str_replace($old, $new, $text, $count);
if (1 !== $count) {
    fwrite(STDERR, "expected 1 Uri::__construct rewrite, got {$count}\n");
    exit(1);
}
if (false === file_put_contents($path, $patched)) {
    fwrite(STDERR, "write failed: {$path}\n");
    exit(1);
}
echo "patched Uri::__construct fast-path absolute path for AOT (#36382)\n";
