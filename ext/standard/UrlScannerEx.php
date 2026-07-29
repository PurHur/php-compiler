<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * PHP-in-PHP port of php-src ext/standard/url_scanner_ex.re (#24370).
 *
 * Rewrites HTML on URL-Rewriter flush: hidden inputs after &lt;form&gt;, query
 * params on tagged URL attributes (url_rewriter.tags / url_rewriter.hosts).
 */
final class UrlScannerEx
{
    private const STATE_PLAIN = 0;

    private const STATE_TAG = 1;

    private const STATE_NEXT_ARG = 2;

    private const STATE_ARG = 3;

    private const STATE_BEFORE_VAL = 4;

    private const STATE_VAL = 5;

    private const TAG_NORMAL = 0;

    private const TAG_FORM = 1;

    private const ATTR_NORMAL = 0;

    private const ATTR_ACTION = 1;

    /**
     * @param list<array{0: string, 1: string}> $pairs ordered rewrite vars (duplicates kept)
     * @param array<string, string>             $tags  tag => attr (attr may be '')
     * @param list<string>                      $hosts lowercase host whitelist (empty = relative + HTTP_HOST)
     */
    public static function adapt(
        string $src,
        array $pairs,
        array $tags,
        array $hosts,
        string $argSeparator
    ): string {
        if ([] === $pairs || [] === $tags) {
            return $src;
        }

        $formApp = '';
        $urlApp = '';
        foreach ($pairs as $i => [$name, $value]) {
            if ($i > 0) {
                $urlApp .= $argSeparator;
            }
            $urlApp .= \rawurlencode($name).'='.\rawurlencode($value);
            $hname = \htmlspecialchars($name, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8', false);
            $hvalue = \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8', false);
            $formApp .= '<input type="hidden" name="'.$hname.'" value="'.$hvalue.'" />';
        }

        return self::scan($src, $tags, $hosts, $formApp, $urlApp, $argSeparator);
    }

    /**
     * @param array<string, string> $tags
     * @param list<string>          $hosts
     */
    private static function scan(
        string $src,
        array $tags,
        array $hosts,
        string $formApp,
        string $urlApp,
        string $argSeparator
    ): string {
        $len = \strlen($src);
        $i = 0;
        $result = '';
        $state = self::STATE_PLAIN;
        $tag = '';
        $tagType = self::TAG_NORMAL;
        $lookupAttr = '';
        $arg = '';
        $attrType = self::ATTR_NORMAL;
        $attrVal = '';

        while ($i < $len) {
            if (self::STATE_PLAIN === $state) {
                $lt = \strpos($src, '<', $i);
                if (false === $lt) {
                    $result .= \substr($src, $i);
                    break;
                }
                $result .= \substr($src, $i, $lt - $i);
                $result .= '<';
                $i = $lt + 1;
                $state = self::STATE_TAG;
                continue;
            }

            if (self::STATE_TAG === $state) {
                $start = $i;
                while ($i < $len && self::isAlphaNamespace($src[$i])) {
                    ++$i;
                }
                if ($i === $start) {
                    $result .= $src[$i];
                    ++$i;
                    $state = self::STATE_PLAIN;
                    continue;
                }
                $rawTag = \substr($src, $start, $i - $start);
                $result .= $rawTag;
                $tag = \strtolower($rawTag);
                if (!\array_key_exists($tag, $tags)) {
                    $state = self::STATE_PLAIN;
                    continue;
                }
                $lookupAttr = \strtolower($tags[$tag]);
                $tagType = ('form' === $tag) ? self::TAG_FORM : self::TAG_NORMAL;
                $attrVal = '';
                $state = self::STATE_NEXT_ARG;
                continue;
            }

            if (self::STATE_NEXT_ARG === $state) {
                while ($i < $len && self::isSpace($src[$i])) {
                    $result .= $src[$i];
                    ++$i;
                }
                if ($i >= $len) {
                    break;
                }
                if ('/' === $src[$i] && ($i + 1) < $len && '>' === $src[$i + 1]) {
                    $result .= '/>';
                    $i += 2;
                    if (self::TAG_FORM === $tagType && '' !== $formApp
                        && self::checkHostWhitelist($attrVal, $hosts)) {
                        $result .= $formApp;
                    }
                    $state = self::STATE_PLAIN;
                    continue;
                }
                if ('>' === $src[$i]) {
                    $result .= '>';
                    ++$i;
                    if (self::TAG_FORM === $tagType && '' !== $formApp
                        && self::checkHostWhitelist($attrVal, $hosts)) {
                        $result .= $formApp;
                    }
                    $state = self::STATE_PLAIN;
                    continue;
                }
                if (self::isAlpha($src[$i])) {
                    $state = self::STATE_ARG;
                    continue;
                }
                $result .= $src[$i];
                ++$i;
                $state = self::STATE_PLAIN;
                continue;
            }

            if (self::STATE_ARG === $state) {
                $start = $i;
                if ($i < $len && self::isAlpha($src[$i])) {
                    ++$i;
                    while ($i < $len && self::isAlphaDash($src[$i])) {
                        ++$i;
                    }
                }
                $rawArg = \substr($src, $start, $i - $start);
                $result .= $rawArg;
                $arg = \strtolower($rawArg);
                $attrType = (self::TAG_FORM === $tagType && 'action' === $arg)
                    ? self::ATTR_ACTION
                    : self::ATTR_NORMAL;
                $state = self::STATE_BEFORE_VAL;
                continue;
            }

            if (self::STATE_BEFORE_VAL === $state) {
                $wsStart = $i;
                while ($i < $len && ' ' === $src[$i]) {
                    ++$i;
                }
                if ($i < $len && '=' === $src[$i]) {
                    $result .= \substr($src, $wsStart, $i - $wsStart);
                    $result .= '=';
                    ++$i;
                    while ($i < $len && ' ' === $src[$i]) {
                        $result .= ' ';
                        ++$i;
                    }
                    $state = self::STATE_VAL;
                    continue;
                }
                $i = $wsStart;
                $state = self::STATE_NEXT_ARG;
                continue;
            }

            // STATE_VAL
            if ($i >= $len) {
                break;
            }
            $q = $src[$i];
            if ('"' === $q || "'" === $q) {
                $result .= $q;
                ++$i;
                $vStart = $i;
                while ($i < $len && $src[$i] !== $q && '>' !== $src[$i]) {
                    ++$i;
                }
                $val = \substr($src, $vStart, $i - $vStart);
                if (self::ATTR_ACTION === $attrType) {
                    $attrVal = $val;
                }
                if ('' !== $lookupAttr && $arg === $lookupAttr && '' !== $urlApp) {
                    $result .= self::appendModifiedUrl($val, $urlApp, $argSeparator, $hosts);
                } else {
                    $result .= $val;
                }
                if ($i < $len && $src[$i] === $q) {
                    $result .= $q;
                    ++$i;
                }
                $state = self::STATE_NEXT_ARG;
                continue;
            }

            // unquoted value
            $vStart = $i;
            while ($i < $len && !self::isSpace($src[$i]) && '>' !== $src[$i]
                && '"' !== $src[$i] && "'" !== $src[$i]) {
                ++$i;
            }
            if ($i === $vStart) {
                $result .= $src[$i];
                ++$i;
                $state = self::STATE_NEXT_ARG;
                continue;
            }
            $val = \substr($src, $vStart, $i - $vStart);
            if (self::ATTR_ACTION === $attrType) {
                $attrVal = $val;
            }
            if ('' !== $lookupAttr && $arg === $lookupAttr && '' !== $urlApp) {
                $result .= self::appendModifiedUrl($val, $urlApp, $argSeparator, $hosts);
            } else {
                $result .= $val;
            }
            $state = self::STATE_NEXT_ARG;
        }

        return $result;
    }

    /**
     * @param list<string> $hosts
     */
    private static function appendModifiedUrl(
        string $url,
        string $urlApp,
        string $argSeparator,
        array $hosts
    ): string {
        $parts = \parse_url($url);
        if (false === $parts) {
            return $url;
        }
        if (isset($parts['fragment']) && '' !== $url && '#' === $url[0]) {
            return $url;
        }
        if (isset($parts['scheme'])) {
            $scheme = \strtolower($parts['scheme']);
            if ('http' !== $scheme && 'https' !== $scheme) {
                return $url;
            }
        }
        if (isset($parts['host'])) {
            $host = \strtolower($parts['host']);
            if ([] === $hosts) {
                $httpHost = self::requestHttpHost();
                if (null === $httpHost || 0 !== \strcasecmp($httpHost, $host)) {
                    return $url;
                }
            } elseif (!\in_array($host, $hosts, true)) {
                return $url;
            }
        }

        $out = '';
        if (isset($parts['scheme'])) {
            $out .= $parts['scheme'].'://';
        } elseif (\strlen($url) >= 2 && '/' === $url[0] && '/' === $url[1]) {
            $out .= '//';
        }
        if (isset($parts['user'])) {
            $out .= $parts['user'];
            if (isset($parts['pass'])) {
                $out .= ':'.$parts['pass'];
            }
            $out .= '@';
        }
        if (isset($parts['host'])) {
            $out .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $out .= ':'.$parts['port'];
        }

        $hasPath = isset($parts['path']);
        $hasQuery = isset($parts['query']);
        $hasFragment = isset($parts['fragment']);
        if (!$hasPath && !$hasQuery && !$hasFragment) {
            return $out.'/?'.$urlApp;
        }
        if ($hasPath) {
            $out .= $parts['path'];
        }
        $out .= '?';
        if ($hasQuery) {
            $out .= $parts['query'].$argSeparator.$urlApp;
        } else {
            $out .= $urlApp;
        }
        if ($hasFragment) {
            $out .= '#'.$parts['fragment'];
        }

        return $out;
    }

    /**
     * @param list<string> $hosts
     */
    private static function checkHostWhitelist(string $actionUrl, array $hosts): bool
    {
        if ('' === $actionUrl) {
            return true;
        }
        $parts = \parse_url($actionUrl);
        if (false === $parts) {
            return false;
        }
        if (isset($parts['scheme'])) {
            $scheme = \strtolower($parts['scheme']);
            if ('http' !== $scheme && 'https' !== $scheme) {
                return false;
            }
        }
        if (!isset($parts['host'])) {
            return true;
        }
        $host = \strtolower($parts['host']);
        if ([] === $hosts) {
            $httpHost = self::requestHttpHost();

            return null !== $httpHost && 0 === \strcasecmp($httpHost, $host);
        }

        return \in_array($host, $hosts, true);
    }

    private static function requestHttpHost(): ?string
    {
        if (!isset($_SERVER['HTTP_HOST']) || !\is_string($_SERVER['HTTP_HOST'])) {
            return null;
        }
        $host = $_SERVER['HTTP_HOST'];
        $colon = \strpos($host, ':');
        if (false !== $colon) {
            $host = \substr($host, 0, $colon);
        }

        return '' === $host ? null : $host;
    }

    private static function isAlpha(string $c): bool
    {
        $o = \ord($c);

        return ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122);
    }

    private static function isAlphaNamespace(string $c): bool
    {
        return self::isAlpha($c) || ':' === $c;
    }

    private static function isAlphaDash(string $c): bool
    {
        return self::isAlpha($c) || '-' === $c;
    }

    private static function isSpace(string $c): bool
    {
        return ' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c || "\v" === $c;
    }
}
