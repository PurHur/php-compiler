<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * Preg-free simple XML scan for user-script AOT loadXML (#18478).
 *
 * Handles documents like {@code <root><a/><b/></root>} for live-tag probes.
 */
final class DomParseSimpleXmlJitHelper
{
    public static function countTagArgv(string $xml, string $tag): int
    {
        $tag = strtolower($tag);
        if ('' === $tag) {
            return 0;
        }
        $needle = '<'.$tag;
        $count = 0;
        $offset = 0;
        while (false !== ($pos = stripos($xml, $needle, $offset))) {
            $after = $pos + \strlen($needle);
            if ($after >= \strlen($xml)) {
                break;
            }
            $next = $xml[$after];
            if ('>' === $next || '/' === $next || ' ' === $next) {
                ++$count;
            }
            $offset = $pos + 1;
        }

        return $count;
    }

    /**
     * @return null|array{0: int, 1: string} match count and first text content
     */
    public static function matchDescendantAttributeArgv(
        string $xml,
        string $tag,
        string $attr,
        string $value
    ): ?array {
        $tag = strtolower($tag);
        $attr = strtolower($attr);
        $needle = '<'.$tag;
        $count = 0;
        $firstText = null;
        $offset = 0;
        while (false !== ($pos = stripos($xml, $needle, $offset))) {
            $gt = strpos($xml, '>', $pos);
            if (false === $gt) {
                break;
            }
            $openTag = substr($xml, $pos, $gt - $pos + 1);
            $attrNeedle = $attr.'="'.str_replace('"', '', $value).'"';
            if (false !== stripos($openTag, $attrNeedle)) {
                $close = stripos($xml, '</'.$tag.'>', $gt + 1);
                if (false !== $close) {
                    $text = substr($xml, $gt + 1, $close - $gt - 1);
                    if (0 === $count) {
                        $firstText = $text;
                    }
                    ++$count;
                }
            }
            $offset = $pos + 1;
        }
        if (0 === $count) {
            return null;
        }

        return [$count, (string) $firstText];
    }

    public static function rootTagArgv(string $xml): string
    {
        if (preg_match('/<([a-zA-Z_][\w:.-]*)/', $xml, $matches)) {
            return strtolower($matches[1]);
        }

        return 'root';
    }

    /** First element child tag under the document element (#19268). */
    public static function firstChildTagArgv(string $xml): ?string
    {
        if (!preg_match('/<([a-zA-Z_][\w:.-]*)(?:\s[^>]*)?>/', $xml, $root, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $afterRoot = (int) $root[0][1] + \strlen($root[0][0]);
        $rest = substr($xml, $afterRoot);
        if (preg_match('/<([a-zA-Z_][\w:.-]*)(?:\s|\/|>)/', $rest, $child)) {
            return $child[1];
        }

        return null;
    }

    /**
     * Find an NS attribute on any element open-tag in compile-time XML (#19268).
     *
     * @return null|array{namespace: string, qname: string, value: string}
     */
    public static function findAttributeNSArgv(string $xml, string $namespace, string $localName): ?array
    {
        $nsDecl = [];
        if (preg_match_all('/xmlns:([A-Za-z_][\w.-]*)\s*=\s*"([^"]*)"/', $xml, $decls, PREG_SET_ORDER)) {
            foreach ($decls as $d) {
                $nsDecl[$d[1]] = $d[2];
            }
        }
        if (preg_match('/xmlns\s*=\s*"([^"]*)"/', $xml, $def)) {
            $nsDecl[''] = $def[1];
        }

        if (!preg_match_all('/<([a-zA-Z_][\w:.-]*)((?:\s[^>]*)?)\/?>/', $xml, $tags, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($tags as $tag) {
            $attrs = $tag[2] ?? '';
            if (!preg_match_all('/([A-Za-z_][\w:.-]*)\s*=\s*"([^"]*)"/', $attrs, $pairs, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($pairs as $pair) {
                $qname = $pair[1];
                if (0 === stripos($qname, 'xmlns')) {
                    continue;
                }
                $pos = strpos($qname, ':');
                $prefix = false === $pos ? '' : substr($qname, 0, $pos);
                $local = false === $pos ? $qname : substr($qname, $pos + 1);
                if (strtolower($local) !== strtolower($localName)) {
                    continue;
                }
                $uri = $nsDecl[$prefix] ?? '';
                if ($uri !== $namespace) {
                    continue;
                }

                return [
                    'namespace' => $uri,
                    'qname' => $qname,
                    'value' => $pair[2],
                ];
            }
        }

        return null;
    }
}
