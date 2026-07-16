<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;

/**
 * Browscap INI loader + UA matcher (ext/standard/browscap.c parity, #3286).
 *
 * php-src: ext/standard/browscap.c — browscap_read_file, browser_reg_compare
 */
final class BrowscapEngine
{
    private const DEFAULT_SECTION = 'DefaultProperties';

    private const NUM_CONTAINS = 3;

    /** @var array<string, list<array{pattern:string,prefix_len:int,contains:array<int,array{0:int,1:int}>,props:array<string,string>}>> */
    private static array $cache = [];

    public static function lookup(Context $ctx, Frame $frame, ?string $userAgent): array|false
    {
        $path = VmBrowser::browscapIniPath($ctx);
        if (false === $path) {
            return false;
        }

        $agent = null !== $userAgent ? $userAgent : self::resolveUserAgent($frame, $ctx);
        if (null === $agent) {
            return false;
        }

        $entries = self::loadEntries($path, $frame);
        if ([] === $entries) {
            return false;
        }

        $lookup = strtolower($agent);
        $found = self::findMatch($entries, $lookup);
        if (null === $found) {
            $found = self::entryByPattern($entries, self::DEFAULT_SECTION);
            if (null === $found) {
                return false;
            }
        }

        return self::entryToArray($found);
    }

    private static function resolveUserAgent(Frame $frame, Context $ctx): ?string
    {
        $server = $ctx->getSuperglobal('_SERVER');
        if (null !== $server && \PHPCompiler\VM\Variable::TYPE_ARRAY === $server->type) {
            $uaVar = $server->toArray()->find('HTTP_USER_AGENT');
            if (null !== $uaVar && \PHPCompiler\VM\Variable::TYPE_STRING === $uaVar->type) {
                $ua = $uaVar->toString();
                if ('' !== $ua) {
                    return $ua;
                }
            }
        }
        if (isset($_SERVER['HTTP_USER_AGENT']) && \is_string($_SERVER['HTTP_USER_AGENT']) && '' !== $_SERVER['HTTP_USER_AGENT']) {
            return $_SERVER['HTTP_USER_AGENT'];
        }
        $iniUa = VmIni::getUserAgent();
        if ('' !== $iniUa) {
            return $iniUa;
        }

        $ctx->errors->triggerError(
            'get_browser(): HTTP_USER_AGENT variable is not set, cannot determine user agent name',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $ctx,
            $frame
        );

        return null;
    }

    /**
     * @return list<array{pattern:string,prefix_len:int,contains:array<int,array{0:int,1:int}>,props:array<string,string>}>
     */
    private static function loadEntries(string $path, Frame $frame): array
    {
        if (isset(self::$cache[$path])) {
            return self::$cache[$path];
        }

        $parsed = VmParseIni::parseFile(
            $frame,
            $path,
            true,
            ParseIniEngine::SCANNER_RAW
        );
        if (false === $parsed) {
            self::$cache[$path] = [];

            return [];
        }

        /** @var array<string, array<string, string>> $sections */
        $sections = [];
        foreach ($parsed as $name => $values) {
            if (!\is_string($name) || !\is_array($values)) {
                continue;
            }
            $sections[$name] = self::normalizeSectionValues($values);
        }

        $entries = [];
        foreach ($sections as $pattern => $rawProps) {
            if ('GJK_Browscap_Version' === $pattern) {
                continue;
            }
            $props = self::resolveInheritedProps($pattern, $sections);
            $entries[] = self::buildEntry($pattern, $props);
        }

        self::$cache[$path] = $entries;

        return $entries;
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private static function normalizeSectionValues(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            $lk = strtolower($key);
            if (!\is_string($value)) {
                if (\is_int($value) || \is_float($value)) {
                    $out[$lk] = (string) $value;
                } elseif (\is_bool($value)) {
                    $out[$lk] = $value ? '1' : '';
                }

                continue;
            }
            $lv = strtolower($value);
            if (\in_array($lv, ['on', 'yes', 'true'], true)) {
                $out[$lk] = '1';
            } elseif (\in_array($lv, ['no', 'off', 'none', 'false'], true)) {
                $out[$lk] = '';
            } else {
                $out[$lk] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, string>> $sections
     *
     * @return array<string, string>
     */
    private static function resolveInheritedProps(string $pattern, array $sections): array
    {
        $chain = [];
        $seen = [];
        $current = $pattern;
        while (isset($sections[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            $chain[] = $current;
            $parent = $sections[$current]['parent'] ?? '';
            if ('' === $parent || $parent === $current) {
                break;
            }
            $current = $parent;
        }

        $props = [];
        foreach (array_reverse($chain) as $section) {
            foreach ($sections[$section] as $key => $value) {
                if ('parent' === $key) {
                    continue;
                }
                $props[$key] = $value;
            }
        }

        return $props;
    }

    /**
     * @param array<string, string> $props
     *
     * @return array{pattern:string,prefix_len:int,contains:array<int,array{0:int,1:int}>,props:array<string,string>}
     */
    private static function buildEntry(string $pattern, array $props): array
    {
        $prefixLen = self::computePrefixLen($pattern);
        $contains = [];
        $pos = $prefixLen;
        for ($i = 0; $i < self::NUM_CONTAINS; ++$i) {
            $pos = self::computeContains($pattern, $pos, $start, $len);
            $contains[] = [$start, $len];
        }

        return [
            'pattern' => $pattern,
            'prefix_len' => $prefixLen,
            'contains' => $contains,
            'props' => $props,
        ];
    }

    private static function computePrefixLen(string $pattern): int
    {
        $len = strlen($pattern);
        for ($i = 0; $i < $len; ++$i) {
            if ('?' === $pattern[$i] || '*' === $pattern[$i]) {
                return $i;
            }
        }

        return $len;
    }

    private static function computeContains(string $pattern, int $startPos, ?int &$containsStart, ?int &$containsLen): int
    {
        $len = strlen($pattern);
        $i = $startPos;
        for (; $i < $len; ++$i) {
            if ('?' !== $pattern[$i] && '*' !== $pattern[$i]) {
                if ($i + 1 < $len && '?' !== $pattern[$i + 1] && '*' !== $pattern[$i + 1]) {
                    break;
                }
            }
        }
        $containsStart = $i;
        for (; $i < $len; ++$i) {
            if ('?' === $pattern[$i] || '*' === $pattern[$i]) {
                break;
            }
        }
        $containsLen = min($i - $containsStart, 255);

        return $i;
    }

    /**
     * @param list<array{pattern:string,prefix_len:int,contains:array<int,array{0:int,1:int}>,props:array<string,string>}> $entries
     *
     * @return array{pattern:string,prefix_len:int,contains:array<int,array{0:int,1:int}>,props:array<string,string>}|null
     */
    private static function findMatch(array $entries, string $lookup): ?array
    {
        $found = null;
        $cachedPrevLen = 0;

        foreach ($entries as $entry) {
            if (strlen($lookup) < self::minimumLength($entry)) {
                continue;
            }

            $prefixMatches = true;
            for ($i = 0; $i < $entry['prefix_len']; ++$i) {
                if (($lookup[$i] ?? '') !== strtolower($entry['pattern'][$i])) {
                    $prefixMatches = false;
                    break;
                }
            }
            if (!$prefixMatches) {
                continue;
            }

            if (self::browserRegCompare($entry, $lookup, $found, $cachedPrevLen)) {
                break;
            }
        }

        return $found;
    }

    /**
     * @param list<array{pattern:string,prefix_len:int,contains:array<int,array{0:int,1:int}>,props:array<string,string>}> $entries
     *
     * @return array{pattern:string,prefix_len:int,contains:array<int,array{0:int,1:int}>,props:array<string,string>}|null
     */
    private static function entryByPattern(array $entries, string $pattern): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['pattern'] === $pattern) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array{pattern:string,prefix_len:int,contains:array<int,array{0:int,1:int}>,props:array<string,string>} $entry
     * @param array{pattern:string,prefix_len:int,contains:array<int,array{0:int,1:int}>,props:array<string,string>}|null $foundEntry
     */
    private static function browserRegCompare(array $entry, string $agentName, ?array &$foundEntry, int &$cachedPrevLen): bool
    {
        $patternLc = strtolower($entry['pattern']);
        $cur = $entry['prefix_len'];

        foreach ($entry['contains'] as [$start, $len]) {
            if (0 === $len) {
                continue;
            }
            $needle = substr($patternLc, $start, $len);
            $pos = strpos($agentName, $needle, $cur);
            if (false === $pos) {
                return false;
            }
            $cur = $pos + $len;
        }

        if ($agentName === $patternLc) {
            $foundEntry = $entry;

            return true;
        }

        if (self::matchStringWildcard(
            substr($agentName, $entry['prefix_len']),
            substr($patternLc, $entry['prefix_len'])
        )) {
            $currLen = $entry['prefix_len'];
            $currentMatch = $entry['pattern'];
            $matchLen = strlen($currentMatch);
            for ($i = $currLen; $i < $matchLen; ++$i) {
                if ('?' !== $currentMatch[$i] && '*' !== $currentMatch[$i]) {
                    ++$currLen;
                }
            }

            if (null !== $foundEntry) {
                if ($cachedPrevLen < $currLen) {
                    $foundEntry = $entry;
                    $cachedPrevLen = $currLen;
                }
            } else {
                $foundEntry = $entry;
                $cachedPrevLen = $currLen;
            }
        }

        return false;
    }

    /**
     * @param array{pattern:string,prefix_len:int,contains:array<int,array{0:int,1:int}>,props:array<string,string>} $entry
     */
    private static function minimumLength(array $entry): int
    {
        $len = $entry['prefix_len'];
        foreach ($entry['contains'] as [, $containsLen]) {
            $len += $containsLen;
        }

        return $len;
    }

    private static function matchStringWildcard(string $subject, string $pattern): bool
    {
        $sEnd = strlen($subject);
        $pEnd = strlen($pattern);
        $sCurrent = 0;
        $pCurrent = 0;
        $wildcardPatternRestore = null;
        $wildcardSRestore = null;

        while ($sCurrent < $sEnd) {
            $patternChar = $pattern[$pCurrent] ?? '';
            $sChar = $subject[$sCurrent];

            if ('*' === $patternChar) {
                ++$pCurrent;
                while ($pCurrent < $pEnd && '*' === $pattern[$pCurrent]) {
                    ++$pCurrent;
                }
                if ($pCurrent === $pEnd) {
                    return true;
                }
                if ('?' !== $pattern[$pCurrent]) {
                    while ($sCurrent < $sEnd && $subject[$sCurrent] !== $pattern[$pCurrent]) {
                        ++$sCurrent;
                    }
                }
                $wildcardPatternRestore = $pCurrent;
                $wildcardSRestore = $sCurrent;

                continue;
            }

            if ($patternChar === $sChar || '?' === $patternChar) {
                ++$pCurrent;
                ++$sCurrent;
                if ($pCurrent === $pEnd) {
                    return $sCurrent === $sEnd;
                }

                continue;
            }

            if (null !== $wildcardPatternRestore) {
                $pCurrent = $wildcardPatternRestore;
                ++$wildcardSRestore;
                $sCurrent = $wildcardSRestore;
            } else {
                return false;
            }
        }

        while ($pCurrent < $pEnd && '*' === $pattern[$pCurrent]) {
            ++$pCurrent;
        }

        return $pCurrent === $pEnd;
    }

    /**
     * @param array{pattern:string,prefix_len:int,contains:array<int,array{0:int,1:int}>,props:array<string,string>} $entry
     *
     * @return array<string, string>
     */
    private static function entryToArray(array $entry): array
    {
        $out = [];
        foreach ($entry['props'] as $key => $value) {
            if ('parent' === $key || 'browser_name_regex' === $key || 'browser_name_pattern' === $key) {
                continue;
            }
            $out[$key] = $value;
        }
        if (isset($entry['props']['parent']) && '' !== $entry['props']['parent']) {
            $out['parent'] = $entry['props']['parent'];
        }
        $out['browser_name_regex'] = self::convertPatternToRegex($entry['pattern']);
        $out['browser_name_pattern'] = $entry['pattern'];

        return $out;
    }

    private static function convertPatternToRegex(string $pattern): string
    {
        $out = '~^';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; ++$i) {
            $c = $pattern[$i];
            switch ($c) {
                case '?':
                    $out .= '.';
                    break;
                case '*':
                    $out .= '.*';
                    break;
                case '.':
                case '\\':
                case '(':
                case ')':
                case '~':
                case '+':
                    $out .= '\\'.$c;
                    break;
                default:
                    $out .= strtolower($c);
            }
        }
        $out .= '$~';

        return $out;
    }
}
