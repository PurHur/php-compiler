<?php
/**
 * #36382 — AOT: parse_url($typedStringParam) SEGVs (Nyholm Uri::__construct).
 */
final class ParseUrlParam
{
    public function path(string $uri): string
    {
        $parts = \parse_url($uri);
        if (false === $parts) {
            return '';
        }

        return isset($parts['path']) ? (string) $parts['path'] : '';
    }
}

echo (new ParseUrlParam())->path('/hello'), "\n";
echo (new ParseUrlParam())->path('https://ex.com/a'), "\n";
