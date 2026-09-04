<?php
/** #36382 bisect — sprintf in throw path only */
final class UriMini
{
    public function __construct(string $uri = '')
    {
        if ('' !== $uri) {
            if (false === \parse_url($uri)) {
                throw new \InvalidArgumentException(\sprintf('Unable to parse URI: "%s"', $uri));
            }
        }
    }
}
new UriMini('https://ex.com');
echo "ok\n";
