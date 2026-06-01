<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * get_meta_tags() — parse &lt;meta name&gt; tags from HTML (ext/standard/php_meta_tags.c).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_meta_tags.c
 */
final class VmMetaTags
{
    /**
     * @return array<string, string>|false
     */
    public static function getMetaTags(string $filename, bool $useIncludePath = false) {
        if ($useIncludePath) {
            $resolved = stream_resolve_include_path($filename);
            if (false !== $resolved) {
                $filename = $resolved;
            }
        }

        $html = VmFs::fileGetContents($filename);
        if (false === $html) {
            return false;
        }

        return self::parseFromHtml($html);
    }

    /**
     * Subset of php_mta_do_parse: only meta tags with a name attribute (php.net).
     *
     * @return array<string, string>
     */
    public static function parseFromHtml(string $html): array
    {
        $result = [];
        $pos = 0;
        $len = \strlen($html);
        while ($pos < $len) {
            $metaPos = stripos($html, '<meta', $pos);
            if (false === $metaPos) {
                break;
            }
            $gtPos = strpos($html, '>', $metaPos);
            if (false === $gtPos) {
                break;
            }
            $tag = substr($html, $metaPos, $gtPos - $metaPos + 1);
            $name = self::extractAttribute($tag, 'name');
            $content = self::extractAttribute($tag, 'content');
            if (null !== $name && null !== $content) {
                $result[self::normalizeMetaName($name)] = $content;
            }
            $pos = $gtPos + 1;
        }

        return $result;
    }

    private static function normalizeMetaName(string $name): string
    {
        $name = strtolower($name);
        $normalized = '';
        $length = \strlen($name);
        for ($i = 0; $i < $length; ++$i) {
            $ch = $name[$i];
            if ('.' === $ch || ' ' === $ch) {
                $normalized .= '_';
            } else {
                $normalized .= $ch;
            }
        }

        return $normalized;
    }

    private static function extractAttribute(string $tag, string $attr): ?string
    {
        $pattern = '/\b' . preg_quote($attr, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\'" >]+))/i';
        if (!preg_match($pattern, $tag, $matches)) {
            return null;
        }
        if (isset($matches[1]) && '' !== $matches[1]) {
            return $matches[1];
        }
        if (isset($matches[2]) && '' !== $matches[2]) {
            return $matches[2];
        }
        if (isset($matches[3])) {
            return $matches[3];
        }

        return '';
    }
}
