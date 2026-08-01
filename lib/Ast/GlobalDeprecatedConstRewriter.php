<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\CompilerVersion;

/**
 * Rewrite attributes on file/namespace constants for nikic/php-parser 4.x (#16819, #23882, #26308).
 *
 * - PHP 8.5+: any attribute including #[\Deprecated] (RFC attributes_on_constants /
 *   Attribute::TARGET_CONSTANT). Zend 8.4 parse-errors attributed file-scope constants.
 *
 * php-parser 4.x rejects `#[Attr] const X` — strip to a comment marker and recover in PHPCfg.
 */
final class GlobalDeprecatedConstRewriter
{
    public const MARKER_PREFIX = 'phpc-global-deprecated-const:';

    /** @internal Marker embedded in source for PHPCfg recovery. */
    public const MARKER_PATTERN = '/\/\*\s*phpc-global-deprecated-const:([^*]+?)\s*\*\//';

    /** PHP 8.5+ general const attributes — rawurlencoded attribute-group source (#23882). */
    public const ATTRS_MARKER_PREFIX = 'phpc-global-const-attrs:';

    /** @internal */
    public const ATTRS_MARKER_PATTERN = '/\/\*\s*phpc-global-const-attrs:([^*]+?)\s*\*\//';

    /**
     * Zend 8.2 reference profile diagnostic for attributed file-scope constants (#16819).
     *
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $source): ?array
    {
        if (false === stripos($source, 'const')) {
            return null;
        }

        $tokens = token_get_all($source);
        $n = \count($tokens);
        $classLikeDepth = 0;
        $pendingClassLike = false;

        for ($i = 0; $i < $n; ++$i) {
            $tok = $tokens[$i];
            $text = self::tokenText($tok);

            if (\is_array($tok)) {
                if (\in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    $pendingClassLike = true;
                } elseif (T_ATTRIBUTE === $tok[0] && 0 === $classLikeDepth) {
                    $j = self::skipAttributeGroupsToConst($tokens, $i);
                    if (null !== $j) {
                        return [
                            'line' => $tok[2] ?? 1,
                            'message' => 'syntax error, unexpected token "const"',
                        ];
                    }
                }
            } elseif ('{' === $text && $pendingClassLike) {
                ++$classLikeDepth;
                $pendingClassLike = false;
            } elseif ('}' === $text && $classLikeDepth > 0) {
                --$classLikeDepth;
            }
        }

        return null;
    }

    public static function rewrite(string $source): string
    {
        $allowDeprecated = CompilerVersion::supportsGlobalDeprecatedConstAttributes();
        $allowAll = CompilerVersion::supportsAttributeTargetConstant();
        if (!$allowDeprecated && !$allowAll) {
            return $source;
        }
        if (false === stripos($source, 'const')) {
            return $source;
        }

        $tokens = token_get_all($source);
        $n = \count($tokens);
        $out = '';
        $classLikeDepth = 0;
        $pendingClassLike = false;

        for ($i = 0; $i < $n; ++$i) {
            $tok = $tokens[$i];
            $text = self::tokenText($tok);

            if (\is_array($tok)) {
                if (\in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    $pendingClassLike = true;
                } elseif (T_ATTRIBUTE === $tok[0] && 0 === $classLikeDepth) {
                    if ($allowAll) {
                        $meta = self::consumeAllAttributeGroups($tokens, $i, $n);
                        if (null === $meta) {
                            $out .= $text;
                            continue;
                        }
                        [$attrsSource, $end] = $meta;
                        $constIdx = self::skipIgnorable($tokens, $end, $n);
                        if ($constIdx >= $n || !\is_array($tokens[$constIdx]) || T_CONST !== $tokens[$constIdx][0]) {
                            $out .= $text;
                            continue;
                        }
                        $out .= '/*'.self::ATTRS_MARKER_PREFIX.rawurlencode($attrsSource).'*/ ';
                        $i = $end - 1;
                        continue;
                    }
                    $meta = self::consumeDeprecatedAttributeGroups($tokens, $i, $n);
                    if (null === $meta) {
                        $out .= $text;
                        continue;
                    }
                    [$payload, $end] = $meta;
                    $constIdx = self::skipIgnorable($tokens, $end, $n);
                    if ($constIdx >= $n || !\is_array($tokens[$constIdx]) || T_CONST !== $tokens[$constIdx][0]) {
                        $out .= $text;
                        continue;
                    }
                    $out .= '/*'.self::MARKER_PREFIX.$payload.'*/ ';
                    $i = $end - 1;
                    continue;
                }
            } elseif ('{' === $text && $pendingClassLike) {
                ++$classLikeDepth;
                $pendingClassLike = false;
            } elseif ('}' === $text && $classLikeDepth > 0) {
                --$classLikeDepth;
            }

            $out .= $text;
        }

        return $source === $out ? $source : $out;
    }

    /**
     * Rebuild PhpParser AttributeGroup[] from a rewritten marker payload (#23882).
     *
     * @return list<\PhpParser\Node\AttributeGroup>
     */
    public static function parseAttrGroupsFromMarkerSource(string $attrsSource): array
    {
        $attrsSource = trim($attrsSource);
        if ('' === $attrsSource) {
            return [];
        }
        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse("<?php\n".$attrsSource."\nclass __PhpcGlobalConstAttrProbe {}\n");
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($ast) || [] === $ast) {
            return [];
        }
        $stmt = $ast[0];
        if (!$stmt instanceof \PhpParser\Node\Stmt\Class_) {
            return [];
        }

        return $stmt->attrGroups;
    }

    public static function parseAttrsMarkerPayload(string $payload): array
    {
        $decoded = rawurldecode(trim($payload));

        return self::parseAttrGroupsFromMarkerSource($decoded);
    }

    public static function parseMarkerPayload(string $payload): ?DeprecatedMetadata
    {
        $payload = trim($payload);
        if ('' === $payload) {
            return null;
        }
        $message = null;
        $since = null;
        foreach (explode('|', $payload) as $chunk) {
            $chunk = trim($chunk);
            if ('' === $chunk) {
                continue;
            }
            if (str_starts_with($chunk, 'message=')) {
                $message = rawurldecode(substr($chunk, 8));
            } elseif (str_starts_with($chunk, 'since=')) {
                $since = rawurldecode(substr($chunk, 6));
            }
        }
        if (null === $message && null === $since && !str_contains($payload, '=')) {
            $since = $payload;
        }

        return new DeprecatedMetadata($message, $since);
    }

    /**
     * Consume every attribute group before a following token (PHP 8.5+ const attrs, #23882).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: string, 1: int}|null [raw attribute source, index after groups]
     */
    private static function consumeAllAttributeGroups(array $tokens, int $start, int $n): ?array
    {
        $i = $start;
        $sawAny = false;
        while ($i < $n) {
            $i = self::skipIgnorable($tokens, $i, $n);
            if ($i >= $n || !\is_array($tokens[$i]) || T_ATTRIBUTE !== $tokens[$i][0]) {
                break;
            }
            $parsed = self::parseAttributeGroup($tokens, $i, $n);
            if (null === $parsed) {
                return null;
            }
            $sawAny = true;
            $i = $parsed[1];
        }
        if (!$sawAny) {
            return null;
        }
        $source = '';
        for ($k = $start; $k < $i; ++$k) {
            $source .= self::tokenText($tokens[$k]);
        }

        return [trim($source), $i];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: string, 1: int}|null [marker payload, index after attribute groups]
     */
    private static function consumeDeprecatedAttributeGroups(array $tokens, int $start, int $n): ?array
    {
        $i = $start;
        $message = null;
        $since = null;
        $sawDeprecated = false;

        while ($i < $n) {
            $i = self::skipIgnorable($tokens, $i, $n);
            if ($i >= $n || !\is_array($tokens[$i]) || T_ATTRIBUTE !== $tokens[$i][0]) {
                break;
            }
            $parsed = self::parseAttributeGroup($tokens, $i, $n);
            if (null === $parsed) {
                return null;
            }
            [$groupMeta, $next] = $parsed;
            if (null !== $groupMeta) {
                $sawDeprecated = true;
                if (null !== $groupMeta['message']) {
                    $message = $groupMeta['message'];
                }
                if (null !== $groupMeta['since']) {
                    $since = $groupMeta['since'];
                }
            }
            $i = $next;
        }

        if (!$sawDeprecated) {
            return null;
        }

        $parts = [];
        if (null !== $message && '' !== $message) {
            $parts[] = 'message='.rawurlencode($message);
        }
        if (null !== $since && '' !== $since) {
            $parts[] = 'since='.rawurlencode($since);
        }

        return [implode('|', $parts), $i];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: ?array{message: ?string, since: ?string}, 1: int}|null
     */
    private static function parseAttributeGroup(array $tokens, int $start, int $n): ?array
    {
        if ($start >= $n || !\is_array($tokens[$start]) || T_ATTRIBUTE !== $tokens[$start][0]) {
            return null;
        }
        $i = $start + 1;
        $deprecated = null;

        while ($i < $n) {
            $i = self::skipIgnorable($tokens, $i, $n);
            if ($i >= $n) {
                return null;
            }
            if (']' === self::tokenText($tokens[$i])) {
                return [$deprecated, $i + 1];
            }

            $nameEnd = self::parseAttributeName($tokens, $i, $n);
            if (null === $nameEnd) {
                return null;
            }
            [$name, $i] = $nameEnd;
            if (self::isDeprecatedAttributeName($name)) {
                $args = self::parseAttributeArgs($tokens, $i, $n);
                if (null === $args) {
                    return null;
                }
                [$deprecated, $i] = $args;
            } else {
                $i = self::skipAttributeArgs($tokens, $i, $n);
                if (null === $i) {
                    return null;
                }
            }

            $i = self::skipIgnorable($tokens, $i, $n);
            if ($i < $n && ',' === self::tokenText($tokens[$i])) {
                ++$i;
                continue;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: string, 1: int}|null
     */
    private static function parseAttributeName(array $tokens, int $i, int $n): ?array
    {
        $i = self::skipIgnorable($tokens, $i, $n);
        if ($i >= $n || !\is_array($tokens[$i])) {
            return null;
        }
        if (!\in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        return [$tokens[$i][1], $i + 1];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: ?array{message: ?string, since: ?string}, 1: int}|null
     */
    private static function parseAttributeArgs(array $tokens, int $i, int $n): ?array
    {
        $i = self::skipIgnorable($tokens, $i, $n);
        if ($i >= $n || '(' !== self::tokenText($tokens[$i])) {
            return [['message' => null, 'since' => null], $i];
        }
        ++$i;
        $message = null;
        $since = null;
        $positional = 0;

        while ($i < $n) {
            $i = self::skipIgnorable($tokens, $i, $n);
            if ($i >= $n) {
                return null;
            }
            if (')' === self::tokenText($tokens[$i])) {
                return [['message' => $message, 'since' => $since], $i + 1];
            }

            $argName = null;
            if (\is_array($tokens[$i]) && T_STRING === $tokens[$i][0]) {
                $next = self::skipIgnorable($tokens, $i + 1, $n);
                if ($next < $n && ':' === self::tokenText($tokens[$next])) {
                    $argName = strtolower($tokens[$i][1]);
                    $i = $next + 1;
                }
            }

            $value = self::parseAttributeScalar($tokens, $i, $n);
            if (null === $value) {
                $i = self::skipExpression($tokens, $i, $n);
            } else {
                [$str, $i] = $value;
                if (null === $argName) {
                    if (0 === $positional) {
                        $message = $str;
                    } elseif (1 === $positional) {
                        $since = $str;
                    }
                    ++$positional;
                } elseif ('message' === $argName) {
                    $message = $str;
                } elseif ('since' === $argName) {
                    $since = $str;
                }
            }

            $i = self::skipIgnorable($tokens, $i, $n);
            if ($i < $n && ',' === self::tokenText($tokens[$i])) {
                ++$i;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipAttributeArgs(array $tokens, int $i, int $n): ?int
    {
        $i = self::skipIgnorable($tokens, $i, $n);
        if ($i >= $n || '(' !== self::tokenText($tokens[$i])) {
            return $i;
        }
        $depth = 1;
        ++$i;
        while ($i < $n && $depth > 0) {
            $ch = self::tokenText($tokens[$i]);
            if ('(' === $ch) {
                ++$depth;
            } elseif (')' === $ch) {
                --$depth;
            }
            ++$i;
        }

        return $i;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: string, 1: int}|null
     */
    private static function parseAttributeScalar(array $tokens, int $i, int $n): ?array
    {
        $i = self::skipIgnorable($tokens, $i, $n);
        if ($i >= $n || !\is_array($tokens[$i])) {
            return null;
        }
        if (T_CONSTANT_ENCAPSED_STRING === $tokens[$i][0]) {
            return [stripcslashes(substr($tokens[$i][1], 1, -1)), $i + 1];
        }
        if (T_LNUMBER === $tokens[$i][0] || T_DNUMBER === $tokens[$i][0]) {
            return [$tokens[$i][1], $i + 1];
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipExpression(array $tokens, int $i, int $n): int
    {
        $depth = 0;
        while ($i < $n) {
            $ch = self::tokenText($tokens[$i]);
            if ('(' === $ch || '[' === $ch) {
                ++$depth;
            } elseif (')' === $ch || ']' === $ch) {
                if (0 === $depth) {
                    return $i;
                }
                --$depth;
            } elseif (0 === $depth && (',' === $ch || ')' === $ch)) {
                return $i;
            }
            ++$i;
        }

        return $i;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipAttributeGroupsToConst(array $tokens, int $start): ?int
    {
        $n = \count($tokens);
        $i = $start;
        while ($i < $n) {
            $i = self::skipIgnorable($tokens, $i, $n);
            if ($i >= $n || !\is_array($tokens[$i]) || T_ATTRIBUTE !== $tokens[$i][0]) {
                break;
            }
            $parsed = self::parseAttributeGroup($tokens, $i, $n);
            if (null === $parsed) {
                return null;
            }
            $i = $parsed[1];
        }
        $constIdx = self::skipIgnorable($tokens, $i, $n);
        if ($constIdx < $n && \is_array($tokens[$constIdx]) && T_CONST === $tokens[$constIdx][0]) {
            return $constIdx;
        }

        return null;
    }

    private static function isDeprecatedAttributeName(string $name): bool
    {
        $name = ltrim($name, '\\');

        return 'Deprecated' === $name || str_ends_with($name, '\\Deprecated');
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipIgnorable(array $tokens, int $i, int $n): int
    {
        while ($i < $n) {
            $tok = $tokens[$i];
            if (!\is_array($tok)) {
                break;
            }
            if (!\in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }
            ++$i;
        }

        return $i;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function tokenText($token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }
}
