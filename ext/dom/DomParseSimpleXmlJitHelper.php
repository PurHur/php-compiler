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
     * XPath 1.0 name-test count for compile-time XML (user-script AOT; #29139).
     *
     * Unprefixed tests match null/empty namespace only — not default-NS elements
     * (php-src ext/dom/xpath.c / libxml; same as VmDomXPath::elementMatchesTag).
     * Prefixed tests expand via $registeredNamespaces (registerNamespace map).
     *
     * @param array<string, string> $registeredNamespaces prefix → URI
     *
     * @return null when a prefixed QName has no registered URI (undefined prefix)
     */
    public static function countXPathNameTestArgv(
        string $xml,
        string $tag,
        array $registeredNamespaces = []
    ): ?int {
        $tag = trim($tag);
        if ('' === $tag) {
            return 0;
        }
        if ('*' === $tag) {
            return \count(self::walkElementsInScopeNamespaces($xml));
        }

        $colon = strpos($tag, ':');
        if (false !== $colon) {
            $prefix = substr($tag, 0, $colon);
            $local = substr($tag, $colon + 1);
            if ('' === $local || !isset($registeredNamespaces[$prefix])) {
                return null;
            }
            $wantUri = $registeredNamespaces[$prefix];

            return self::countElementsMatchingNameTest($xml, $local, $wantUri);
        }

        // Unprefixed name test — null/empty namespace URI only (#21125 / #29139).
        return self::countElementsMatchingNameTest($xml, $tag, null);
    }

    /**
     * @param string|null $wantUri null = unprefixed (empty NS only); string = expanded URI
     */
    private static function countElementsMatchingNameTest(
        string $xml,
        string $localName,
        ?string $wantUri
    ): int {
        $localName = strtolower($localName);
        $count = 0;
        foreach (self::walkElementsInScopeNamespaces($xml) as $element) {
            if ('*' !== $localName && 0 !== strcasecmp($element['local'], $localName)) {
                continue;
            }
            $prefix = $element['prefix'];
            $ns = $element['inScope'][$prefix] ?? '';
            if (null !== $wantUri) {
                if ($ns === $wantUri) {
                    ++$count;
                }
                continue;
            }
            if ('' === $ns) {
                ++$count;
            }
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
        string $value,
        bool $numericCompare = false
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
            $matched = $numericCompare
                ? self::openTagAttrMatches($openTag, $attr, $value, true)
                : (false !== stripos($openTag, $attr.'="'.str_replace('"', '', $value).'"')
                    || false !== stripos($openTag, $attr."='".str_replace("'", '', $value)."'"));
            if ($matched) {
                // Self-closing empty elements (e.g. <a id="1"/>) have no </tag> (#28647).
                // Mirror nthTagTextArgv: count them with empty textContent.
                if ($gt > $pos && '/' === $xml[$gt - 1]) {
                    $text = '';
                } else {
                    $close = stripos($xml, '</'.$tag.'>', $gt + 1);
                    if (false === $close) {
                        $text = '';
                    } else {
                        $text = substr($xml, $gt + 1, $close - $gt - 1);
                    }
                }
                if (0 === $count) {
                    $firstText = $text;
                }
                ++$count;
            }
            $offset = $pos + 1;
        }
        if (0 === $count) {
            return null;
        }

        return [$count, (string) $firstText];
    }

    /** Attribute value from an open tag, or null if absent. */
    public static function openTagAttrValue(string $openTag, string $attr): ?string
    {
        $attr = strtolower($attr);
        if (preg_match('/\b'.preg_quote($attr, '/').'\s*=\s*"([^"]*)"/i', $openTag, $m)
            || preg_match("/\b".preg_quote($attr, '/')."\s*=\s*'([^']*)'/i", $openTag, $m)
        ) {
            return $m[1];
        }

        return null;
    }

    /**
     * String or XPath 1.0 number equality for an attribute on an open tag (#24333).
     */
    public static function openTagAttrMatches(
        string $openTag,
        string $attr,
        string $expected,
        bool $numericCompare
    ): bool {
        $actual = self::openTagAttrValue($openTag, $attr);
        if (null === $actual) {
            return false;
        }
        if (!$numericCompare) {
            return 0 === strcasecmp($actual, $expected);
        }
        $left = trim($actual);
        $right = trim($expected);
        if ('' === $left || !is_numeric($left) || '' === $right || !is_numeric($right)) {
            return false;
        }

        return (float) $left === (float) $right;
    }

    /** First attribute value for //@attr document order (#19352). */
    public static function firstAttributeValueArgv(string $xml, string $attr): ?string
    {
        $attr = strtolower($attr);
        if (!preg_match_all('/<[^>]+>/', $xml, $tags)) {
            return null;
        }
        foreach ($tags[0] as $openTag) {
            if (preg_match('/\b'.preg_quote($attr, '/').'\s*=\s*"([^"]*)"/i', $openTag, $m)
                || preg_match("/\b".preg_quote($attr, '/')."\s*=\s*'([^']*)'/i", $openTag, $m)
            ) {
                return $m[1];
            }
        }

        return null;
    }

    /** Nth (1-based) matching tag's attribute value for //tag[n]/@attr (#19352). */
    public static function nthTagAttributeValueArgv(
        string $xml,
        string $tag,
        string $attr,
        int $position
    ): ?string {
        $openTag = self::nthTagOpenTagArgv($xml, $tag, $position);
        if (null === $openTag) {
            return null;
        }

        return self::openTagAttrValue($openTag, $attr);
    }

    /**
     * Nth (1-based) matching open-tag markup for //tag NodeList::item() (#27275).
     */
    public static function nthTagOpenTagArgv(string $xml, string $tag, int $position): ?string
    {
        if ($position < 1) {
            return null;
        }
        $tag = strtolower($tag);
        $needle = '<'.$tag;
        $seen = 0;
        $offset = 0;
        while (false !== ($pos = stripos($xml, $needle, $offset))) {
            $after = $pos + \strlen($needle);
            if ($after >= \strlen($xml)) {
                break;
            }
            $next = $xml[$after];
            if ('>' !== $next && '/' !== $next && ' ' !== $next) {
                $offset = $pos + 1;
                continue;
            }
            $gt = strpos($xml, '>', $pos);
            if (false === $gt) {
                break;
            }
            ++$seen;
            if ($seen === $position) {
                return substr($xml, $pos, $gt - $pos + 1);
            }
            $offset = $pos + 1;
        }

        return null;
    }

    /**
     * Attributes from an open-tag string (xmlns* skipped) for user-script AOT (#27275).
     *
     * @return list<array{qname: string, value: string}>
     */
    public static function attributesFromOpenTagArgv(string $openTag): array
    {
        if (!preg_match('/^<([a-zA-Z_][\w:.-]*)((?:\s[^>]*)?)\/?>/', $openTag, $root)) {
            return [];
        }
        $attrs = $root[2] ?? '';
        if ('' === trim($attrs)) {
            return [];
        }
        $out = [];
        if (!preg_match_all('/([A-Za-z_][\w:.-]*)\s*=\s*"([^"]*)"/', $attrs, $pairs, PREG_SET_ORDER)
            && !preg_match_all("/([A-Za-z_][\w:.-]*)\s*=\s*'([^']*)'/", $attrs, $pairs, PREG_SET_ORDER)
        ) {
            return [];
        }
        foreach ($pairs as $pair) {
            $qname = $pair[1];
            if (0 === stripos($qname, 'xmlns')) {
                continue;
            }
            $out[] = ['qname' => $qname, 'value' => $pair[2]];
        }

        return $out;
    }

    /**
     * Attribute value on the first //tag[@predAttr="predVal"] element (#21148).
     */
    public static function matchingTagAttributeValueArgv(
        string $xml,
        string $tag,
        string $predAttr,
        string $predValue,
        string $attr,
        bool $numericCompare = false
    ): ?string {
        $tag = strtolower($tag);
        $predAttr = strtolower($predAttr);
        $attr = strtolower($attr);
        $needle = '<'.$tag;
        $offset = 0;
        while (false !== ($pos = stripos($xml, $needle, $offset))) {
            $after = $pos + \strlen($needle);
            if ($after >= \strlen($xml)) {
                break;
            }
            $next = $xml[$after];
            if ('>' !== $next && '/' !== $next && ' ' !== $next) {
                $offset = $pos + 1;
                continue;
            }
            $gt = strpos($xml, '>', $pos);
            if (false === $gt) {
                break;
            }
            $openTag = substr($xml, $pos, $gt - $pos + 1);
            if (!self::openTagAttrMatches($openTag, $predAttr, $predValue, $numericCompare)) {
                $offset = $pos + 1;
                continue;
            }
            $actual = self::openTagAttrValue($openTag, $attr);
            if (null !== $actual) {
                return $actual;
            }

            return null;
        }

        return null;
    }

    /**
     * Count //tag[@pred=v]/@attr nodes (elements matching pred that expose @attr) (#24333).
     */
    public static function countMatchingTagAttributeAxisArgv(
        string $xml,
        string $tag,
        string $predAttr,
        string $predValue,
        string $attr,
        bool $numericCompare = false
    ): int {
        $tag = strtolower($tag);
        $predAttr = strtolower($predAttr);
        $attr = strtolower($attr);
        $needle = '<'.$tag;
        $count = 0;
        $offset = 0;
        while (false !== ($pos = stripos($xml, $needle, $offset))) {
            $after = $pos + \strlen($needle);
            if ($after >= \strlen($xml)) {
                break;
            }
            $next = $xml[$after];
            if ('>' !== $next && '/' !== $next && ' ' !== $next) {
                $offset = $pos + 1;
                continue;
            }
            $gt = strpos($xml, '>', $pos);
            if (false === $gt) {
                break;
            }
            $openTag = substr($xml, $pos, $gt - $pos + 1);
            if (self::openTagAttrMatches($openTag, $predAttr, $predValue, $numericCompare)
                && null !== self::openTagAttrValue($openTag, $attr)
            ) {
                ++$count;
            }
            $offset = $pos + 1;
        }

        return $count;
    }

    /** First descendant element's concatenated text for //tag (#19352). */
    public static function firstTagTextArgv(string $xml, string $tag): ?string
    {
        return self::nthTagTextArgv($xml, $tag, 1);
    }

    /** Nth (1-based) descendant element's text for //tag[n] (#19456). */
    public static function nthTagTextArgv(string $xml, string $tag, int $position): ?string
    {
        if ($position < 1) {
            return null;
        }
        $tag = strtolower($tag);
        $needle = '<'.$tag;
        $seen = 0;
        $offset = 0;
        while (false !== ($pos = stripos($xml, $needle, $offset))) {
            $after = $pos + \strlen($needle);
            if ($after >= \strlen($xml)) {
                break;
            }
            $next = $xml[$after];
            if ('>' !== $next && '/' !== $next && ' ' !== $next) {
                $offset = $pos + 1;
                continue;
            }
            $gt = strpos($xml, '>', $pos);
            if (false === $gt) {
                break;
            }
            ++$seen;
            if ($seen !== $position) {
                $offset = $pos + 1;
                continue;
            }
            if ($gt > $pos && '/' === $xml[$gt - 1]) {
                return '';
            }
            $close = stripos($xml, '</'.$tag.'>', $gt + 1);
            if (false === $close) {
                return '';
            }

            return substr($xml, $gt + 1, $close - $gt - 1);
        }

        return null;
    }

    public static function rootTagArgv(string $xml): string
    {
        if (preg_match('/<([a-zA-Z_][\w:.-]*)/', $xml, $matches)) {
            return strtolower($matches[1]);
        }

        return 'root';
    }

    /**
     * Root open-tag attributes for user-script AOT createFromString / loadXML (#27108).
     *
     * @return list<array{qname: string, value: string}>
     */
    public static function rootAttributesArgv(string $xml): array
    {
        if (!preg_match('/<([a-zA-Z_][\w:.-]*)((?:\s[^>]*)?)\/?>/', $xml, $root)) {
            return [];
        }
        $attrs = $root[2] ?? '';
        if ('' === trim($attrs)) {
            return [];
        }
        $out = [];
        if (!preg_match_all('/([A-Za-z_][\w:.-]*)\s*=\s*"([^"]*)"/', $attrs, $pairs, PREG_SET_ORDER)
            && !preg_match_all("/([A-Za-z_][\w:.-]*)\s*=\s*'([^']*)'/", $attrs, $pairs, PREG_SET_ORDER)
        ) {
            return [];
        }
        foreach ($pairs as $pair) {
            $qname = $pair[1];
            if (0 === stripos($qname, 'xmlns')) {
                continue;
            }
            $out[] = ['qname' => $qname, 'value' => $pair[2]];
        }

        return $out;
    }

    /**
     * Document-element textContent for user-script AOT (#25475).
     *
     * Concatenates descendant character data (tags stripped) — matches Zend
     * {@code DOMNode::textContent} for the thin loadXML literal path.
     */
    public static function rootTextContentArgv(string $xml): string
    {
        if (!preg_match('/<([a-zA-Z_][\w:.-]*)(?:\s[^>]*)?(\/?)>/', $xml, $root, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        if ('/' === ($root[2][0] ?? '')) {
            return '';
        }
        $tag = $root[1][0];
        $afterRoot = (int) $root[0][1] + \strlen($root[0][0]);
        $close = stripos($xml, '</'.$tag.'>', $afterRoot);
        $inner = false === $close
            ? substr($xml, $afterRoot)
            : substr($xml, $afterRoot, $close - $afterRoot);
        if ('' === $inner) {
            return '';
        }

        return preg_replace('/<[^>]*>/', '', $inner) ?? '';
    }

    /** First element child tag under the document element (#19268). */
    public static function firstChildTagArgv(string $xml): ?string
    {
        $node = self::firstChildNodeArgv($xml);
        if (null === $node || 'element' !== $node['kind']) {
            return null;
        }

        return $node['data'];
    }

    /**
     * True when the document element has at least one element child (#26757).
     */
    public static function rootHasElementChildren(string $xml): bool
    {
        return [] !== self::directElementChildTags($xml);
    }

    /**
     * Raw inner markup of the document element for user-script saveXML (#26757).
     *
     * Includes element children (e.g. {@code <x/>}) so PROP_USER_SCRIPT_INNER_XML is
     * non-empty after loadXML — textContent alone strips tags.
     */
    public static function rootInnerXmlArgv(string $xml): string
    {
        if (!preg_match('/<([a-zA-Z_][\w:.-]*)(?:\s[^>]*)?(\/?)>/', $xml, $root, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        if ('/' === ($root[2][0] ?? '')) {
            return '';
        }
        $tag = $root[1][0];
        $afterRoot = (int) $root[0][1] + \strlen($root[0][0]);
        $close = stripos($xml, '</'.$tag.'>', $afterRoot);
        if (false === $close) {
            return substr($xml, $afterRoot);
        }

        return substr($xml, $afterRoot, $close - $afterRoot);
    }

    /**
     * Parse a single element's outer markup into tag / attr suffix / inner XML.
     *
     * Used by {@see JitDomCloneNode} (php-src xmlDocCopyNode). Attr suffix keeps the
     * leading space so saveXML can concat it onto the open tag (xmlns attr slot).
     *
     * @return null|array{tag: string, attrs: string, inner: string}
     */
    public static function parseElementMarkupArgv(string $markup): ?array
    {
        $markup = trim($markup);
        if (1 !== preg_match('/^<([a-zA-Z_][\w:.-]*)((?:\s[^>]*)?)(\/?)>/', $markup, $el)) {
            return null;
        }
        $tag = $el[1];
        $attrs = rtrim($el[2] ?? '', " \t/");
        $selfClosing = '/' === ($el[3] ?? '');
        if ($selfClosing) {
            return ['tag' => $tag, 'attrs' => $attrs, 'inner' => ''];
        }
        $openLen = \strlen($el[0]);
        $close = stripos($markup, '</'.$tag.'>');
        $inner = false === $close
            ? substr($markup, $openLen)
            : substr($markup, $openLen, $close - $openLen);

        return ['tag' => $tag, 'attrs' => $attrs, 'inner' => $inner];
    }

    /**
     * Replace the outer markup of direct child {@code $index} under the document
     * element — used by thin-AOT replaceChild so saveXML keeps siblings (#28671).
     *
     * @return string|null New root-inner XML, or null when index is out of range
     */
    public static function rootInnerXmlReplaceChildAt(
        string $xml,
        int $index,
        string $replacementMarkup
    ): ?string {
        $inner = self::rootInnerXmlArgv($xml);
        if ('' === $inner) {
            return 0 === $index ? $replacementMarkup : null;
        }
        $chunks = self::directChildMarkupChunks($inner);
        if ($index < 0 || $index >= \count($chunks)) {
            return null;
        }
        $chunks[$index] = $replacementMarkup;

        return implode('', $chunks);
    }

    /**
     * Move direct child {@code $index} to the end of root-inner XML (#31684).
     *
     * Peer {@see rootInnerXmlReplaceChildAt}: thin-AOT appendChild same-parent move
     * must reorder PROP_USER_SCRIPT_INNER_XML (not concat a fresh {@code <tag/>}).
     *
     * @return string|null New root-inner XML, or null when index is out of range
     */
    public static function rootInnerXmlMoveChildToEnd(string $xml, int $index): ?string
    {
        $inner = self::rootInnerXmlArgv($xml);
        if ('' === $inner) {
            return null;
        }

        return self::moveChildMarkupToEnd($inner, $index);
    }

    /**
     * Rotate one direct-child markup chunk to the end of {@code $inner} (#31684).
     *
     * @return string|null
     */
    public static function moveChildMarkupToEnd(string $inner, int $index): ?string
    {
        $chunks = self::directChildMarkupChunks($inner);
        if ($index < 0 || $index >= \count($chunks)) {
            return null;
        }
        $moved = $chunks[$index];
        array_splice($chunks, $index, 1);
        $chunks[] = $moved;

        return implode('', $chunks);
    }

    /**
     * Outer-markup slices of each direct child under {@code $inner} (#28671).
     *
     * @return list<string>
     */
    public static function directChildMarkupChunks(string $inner): array
    {
        $chunks = [];
        $len = \strlen($inner);
        $i = 0;
        while ($i < $len) {
            if (1 === preg_match('/\G<!--.*?-->/s', $inner, $comment, 0, $i)) {
                $chunks[] = $comment[0];
                $i += \strlen($comment[0]);

                continue;
            }
            if (1 === preg_match('/\G<([a-zA-Z_][\w:.-]*)((?:\s[^>]*)?)(\/?)>/', $inner, $el, 0, $i)) {
                $start = $i;
                $tag = $el[1];
                $selfClosing = '/' === ($el[3] ?? '');
                $i += \strlen($el[0]);
                if (!$selfClosing) {
                    $close = stripos($inner, '</'.$tag.'>', $i);
                    if (false !== $close) {
                        $i = $close + \strlen('</'.$tag.'>');
                    }
                }
                $chunks[] = substr($inner, $start, $i - $start);

                continue;
            }
            if (1 === preg_match('/\G([^<]+)/', $inner, $text, 0, $i)) {
                $chunks[] = $text[1];
                $i += \strlen($text[1]);

                continue;
            }

            break;
        }

        return $chunks;
    }

    /**
     * Direct element-child tag names under the document element (#23251 / #26757).
     *
     * @return list<string>
     */
    public static function directElementChildTags(string $xml): array
    {
        $tags = [];
        foreach (self::directChildNodesArgv($xml) as $node) {
            if ('element' === $node['kind']) {
                $tags[] = $node['data'];
            }
        }

        return $tags;
    }

    /**
     * Direct child nodes under the document element, including blank text (#27260).
     *
     * Compile-time only (host preg). Keeps inter-element whitespace so AOT
     * {@code childNodes->length} matches Zend when preserveWhiteSpace is default.
     *
     * @return list<array{kind: 'comment'|'text'|'element', data: string}>
     */
    public static function directChildNodesArgv(string $xml): array
    {
        $inner = self::rootInnerXmlArgv($xml);
        if ('' === $inner) {
            return [];
        }
        $nodes = [];
        $len = \strlen($inner);
        $i = 0;
        while ($i < $len) {
            if (1 === preg_match('/\G<!--(.*?)-->/s', $inner, $comment, 0, $i)) {
                $nodes[] = ['kind' => 'comment', 'data' => $comment[1]];
                $i += \strlen($comment[0]);

                continue;
            }
            if (1 === preg_match('/\G<([a-zA-Z_][\w:.-]*)((?:\s[^>]*)?)(\/?)>/', $inner, $el, 0, $i)) {
                $tag = $el[1];
                $selfClosing = '/' === ($el[3] ?? '');
                $i += \strlen($el[0]);
                if (!$selfClosing) {
                    $close = stripos($inner, '</'.$tag.'>', $i);
                    if (false !== $close) {
                        $i = $close + \strlen('</'.$tag.'>');
                    }
                }
                $nodes[] = ['kind' => 'element', 'data' => strtolower($tag)];

                continue;
            }
            if (1 === preg_match('/\G([^<]+)/', $inner, $text, 0, $i)) {
                $nodes[] = ['kind' => 'text', 'data' => $text[1]];
                $i += \strlen($text[1]);

                continue;
            }

            break;
        }

        return $nodes;
    }

    /**
     * First child under the document element for user-script AOT navigation (#19455).
     *
     * @return null|array{kind: 'comment'|'text'|'element', data: string}
     */
    public static function firstChildNodeArgv(string $xml): ?array
    {
        if (!preg_match('/<([a-zA-Z_][\w:.-]*)(?:\s[^>]*)?>/', $xml, $root, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $afterRoot = (int) $root[0][1] + \strlen($root[0][0]);
        $rest = substr($xml, $afterRoot);
        if (preg_match('/^\s*<!--(.*?)-->/s', $rest, $comment)) {
            return ['kind' => 'comment', 'data' => $comment[1]];
        }
        if (preg_match('/^\s*<([a-zA-Z_][\w:.-]*)(?:\s|\/|>)/', $rest, $child)) {
            return ['kind' => 'element', 'data' => $child[1]];
        }
        if (preg_match('/^([^<]+)/', $rest, $text)) {
            $data = $text[1];
            // Strip trailing whitespace that only pads a following close tag.
            if (preg_match('/^(.*?)(\s*)$/s', $data, $parts)
                && '' !== $parts[1]
            ) {
                return ['kind' => 'text', 'data' => $parts[1]];
            }
            if ('' !== trim($data)) {
                return ['kind' => 'text', 'data' => $data];
            }
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

    /**
     * Count XPath namespace-axis nodes from compile-time XML (user-script AOT; #20206).
     *
     * Supports {@code //namespace::*}, {@code //tag/namespace::*}, {@code /tag/namespace::prefix}.
     * Returns null when the expression is not a supported namespace-axis shape.
     */
    public static function countNamespaceAxisArgv(string $xml, string $expression): ?int
    {
        $expression = trim($expression);
        $wantPrefix = null;
        $filterTag = null;
        $absoluteRootOnly = false;

        if (preg_match('~^//namespace::(\*|[\w.-]+)$~', $expression, $matches)) {
            $wantPrefix = '*' === $matches[1] ? null : $matches[1];
        } elseif (preg_match('~^//([*\w][\w:-]*)/namespace::(\*|[\w.-]+)$~', $expression, $matches)) {
            $filterTag = strtolower($matches[1]);
            $wantPrefix = '*' === $matches[2] ? null : $matches[2];
        } elseif (preg_match('~^/([*\w][\w:-]*)/namespace::(\*|[\w.-]+)$~', $expression, $matches)) {
            $filterTag = strtolower($matches[1]);
            $wantPrefix = '*' === $matches[2] ? null : $matches[2];
            $absoluteRootOnly = true;
        } else {
            return null;
        }

        $count = 0;
        $seenRoot = false;
        foreach (self::walkElementsInScopeNamespaces($xml) as $element) {
            $local = strtolower($element['local']);
            if ($absoluteRootOnly) {
                if ($seenRoot) {
                    continue;
                }
                $seenRoot = true;
                if (null !== $filterTag && $local !== $filterTag) {
                    continue;
                }
            } elseif (null !== $filterTag && $local !== $filterTag) {
                continue;
            }
            foreach ($element['inScope'] as $prefix => $_) {
                if (null !== $wantPrefix && $prefix !== $wantPrefix) {
                    continue;
                }
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Document-order elements matching getElementsByTagNameNS (#32415).
     *
     * php-src: ext/dom/php_dom.c php_dom_get_elements_by_tag_name_ns_helper —
     * namespace "*" matches any URI (including empty); localName "*" matches any
     * local name; otherwise xmlStrEqual on URI + local.
     *
     * @return list<array{local: string, prefix: string, qname: string, ns: string}>
     */
    public static function elementsByTagNameNSArgv(
        string $xml,
        string $namespaceUri,
        string $localName
    ): array {
        $matches = [];
        foreach (self::walkElementsInScopeNamespaces($xml) as $element) {
            $ns = $element['inScope'][$element['prefix']] ?? '';
            $nsMatch = '*' === $namespaceUri || $ns === $namespaceUri;
            $nameMatch = '*' === $localName || $element['local'] === $localName;
            if ($nsMatch && $nameMatch) {
                $matches[] = [
                    'local' => $element['local'],
                    'prefix' => $element['prefix'],
                    'qname' => $element['qname'],
                    'ns' => $ns,
                ];
            }
        }

        return $matches;
    }

    public static function countElementsByTagNameNSArgv(
        string $xml,
        string $namespaceUri,
        string $localName
    ): int {
        return \count(self::elementsByTagNameNSArgv($xml, $namespaceUri, $localName));
    }

    /**
     * @return null|array{local: string, prefix: string, qname: string, ns: string}
     */
    public static function nthElementByTagNameNSArgv(
        string $xml,
        string $namespaceUri,
        string $localName,
        int $index
    ): ?array {
        if ($index < 0) {
            return null;
        }
        $matches = self::elementsByTagNameNSArgv($xml, $namespaceUri, $localName);

        return $matches[$index] ?? null;
    }

    /**
     * Document-order elements with in-scope prefix→URI maps (xml always present; #20097/#20206).
     *
     * @return list<array{local: string, prefix: string, qname: string, inScope: array<string, string>}>
     */
    public static function walkElementsInScopeNamespaces(string $xml): array
    {
        $xmlNs = 'http://www.w3.org/XML/1998/namespace';
        $out = [];
        $stack = [];
        $inScope = ['xml' => $xmlNs];

        if (!preg_match_all(
            '/<\/?([A-Za-z_][\w:.-]*)((?:\s[^>]*)?)\s*(\/?)>/',
            $xml,
            $tags,
            PREG_SET_ORDER
        )) {
            return $out;
        }

        foreach ($tags as $tag) {
            $full = $tag[0];
            if (0 === strpos($full, '</')) {
                if ([] === $stack) {
                    continue;
                }
                array_pop($stack);
                $inScope = ['xml' => $xmlNs];
                foreach ($stack as $frame) {
                    foreach ($frame as $prefix => $uri) {
                        unset($inScope[$prefix]);
                        $inScope[$prefix] = $uri;
                    }
                }
                continue;
            }

            $qname = $tag[1];
            $attrs = $tag[2] ?? '';
            $selfClosing = '/' === ($tag[3] ?? '');
            $colon = strpos($qname, ':');
            $prefix = false === $colon ? '' : substr($qname, 0, $colon);
            $local = false === $colon ? $qname : substr($qname, $colon + 1);

            $decls = [];
            if (preg_match_all('/\sxmlns:([A-Za-z_][\w.-]*)\s*=\s*"([^"]*)"/', $attrs, $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as $pair) {
                    $decls[$pair[1]] = $pair[2];
                }
            }
            if (preg_match('/\sxmlns\s*=\s*"([^"]*)"/', $attrs, $def)) {
                $decls[''] = $def[1];
            }

            foreach ($decls as $declPrefix => $uri) {
                unset($inScope[$declPrefix]);
                $inScope[$declPrefix] = $uri;
            }

            $out[] = [
                'local' => $local,
                'prefix' => $prefix,
                'qname' => $qname,
                'inScope' => $inScope,
            ];

            if ($selfClosing) {
                $inScope = ['xml' => $xmlNs];
                foreach ($stack as $frame) {
                    foreach ($frame as $framePrefix => $uri) {
                        unset($inScope[$framePrefix]);
                        $inScope[$framePrefix] = $uri;
                    }
                }
            } else {
                $stack[] = $decls;
            }
        }

        return $out;
    }
}
