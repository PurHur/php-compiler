<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;

/**
 * Rewrite `final` on constructor-promoted properties for nikic/php-parser 4.x (#22451, #27123).
 *
 * php-src: Zend/zend_language_parser.y — promoted property_modifier includes `final`
 * (PHP 8.5+ only; gated via {@see CompilerVersion::supportsFinalPromotedProperties()}).
 * Zend ≤8.4: {@code Cannot use the final modifier on a parameter}.
 * Zend/zend_compile.c — promotion + ZEND_ACC_FINAL (inheritance only — writes still allowed).
 *
 * php-parser 4.x rejects `public final string $x` in a param list (`unexpected T_FINAL`).
 * Strip `final`, embed a marker comment recovered from Param attributes at compile time.
 * Bare `final Type $x` (no visibility) promotes as public in Zend — rewrite inserts `public`.
 */
final class FinalPromotedPropertyRewriter
{
    private const MARKER = 'phpc-promoted-final';

    /** @internal Marker embedded in source for Param attribute recovery. */
    public const MARKER_PATTERN = '/\/\*\s*phpc-promoted-final\s*\*\//i';

    /** Zend ≤8.4 / PROFILE≤8.4 compile fatal (#27123, Zend/zend_compile.c). */
    public const REFERENCE_PROFILE_FINAL_ON_PARAMETER = 'Cannot use the final modifier on a parameter';

    public static function containsFinalPromotedPropertySyntax(string $source): bool
    {
        return null !== self::firstFinalPromotedOffset($source);
    }

    /**
     * @return array{line: int, message: string}|null
     */
    public static function referenceProfileSyntaxError(string $source): ?array
    {
        $offset = self::firstFinalPromotedOffset($source);
        if (null === $offset) {
            return null;
        }

        return [
            'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
            'message' => self::REFERENCE_PROFILE_FINAL_ON_PARAMETER,
        ];
    }

    public static function rewrite(string $source): string
    {
        if (!CompilerVersion::supportsFinalPromotedProperties()) {
            return $source;
        }
        if (!self::containsFinalPromotedPropertySyntax($source)) {
            return $source;
        }

        // Locate __construct on the blanked view so string/heredoc bodies are ignored (#28481),
        // then rewrite the matching spans in the original source (offsets stay aligned).
        $inspectable = self::blankOpaqueRegions($source);
        $offset = 0;
        $out = '';
        $cursor = 0;
        while (preg_match('/\bfunction\s+__construct\s*\(/i', $inspectable, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $openPos = $m[0][1] + strlen($m[0][0]) - 1;
            $paramsText = self::extractBalancedParenContent($source, $openPos);
            if (null === $paramsText) {
                $offset = $openPos + 1;
                continue;
            }
            $closePos = $openPos + 1 + strlen($paramsText);
            $rewritten = self::rewriteParams($paramsText);
            $out .= substr($source, $cursor, $openPos + 1 - $cursor);
            $out .= $rewritten;
            $cursor = $closePos;
            $offset = $closePos;
        }
        $out .= substr($source, $cursor);

        return $out;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function isFinalFromAttributes(array $attributes): bool
    {
        foreach (self::commentChunksFromAttributes($attributes) as $chunk) {
            if (preg_match(self::MARKER_PATTERN, $chunk)) {
                return true;
            }
        }

        return false;
    }

    private static function paramsContainFinalPromoted(string $paramsText): bool
    {
        foreach (self::splitTopLevelParams($paramsText) as $param) {
            if (self::paramIsFinalPromoted($param)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Byte offset of the first `final` token in a promoted ctor param, or null.
     *
     * Scans a blanked view so `eval('… public final …')` / string literals do not
     * false-positive as declarations (#28481, cf. AsymmetricVisibilityRewriter).
     */
    private static function firstFinalPromotedOffset(string $source): ?int
    {
        $inspectable = self::blankOpaqueRegions($source);
        if (!preg_match('/\bfunction\s+__construct\s*\(/i', $inspectable)) {
            return null;
        }

        $offset = 0;
        while (preg_match('/\bfunction\s+__construct\s*\(/i', $inspectable, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $openPos = $m[0][1] + strlen($m[0][0]) - 1;
            // Params text from original source — blanking only spaces opaque regions.
            $paramsText = self::extractBalancedParenContent($source, $openPos);
            if (null !== $paramsText && self::paramsContainFinalPromoted($paramsText)) {
                $paramsStart = $openPos + 1;
                foreach (self::splitTopLevelParams($paramsText) as $param) {
                    if (!self::paramIsFinalPromoted($param)) {
                        continue;
                    }
                    $foundAt = strpos($paramsText, $param);
                    if (false === $foundAt) {
                        return $paramsStart;
                    }
                    $head = substr($param, 0, (int) strpos($param, '$'));
                    if (preg_match('/\bfinal\b/i', $head, $fm, PREG_OFFSET_CAPTURE)) {
                        return $paramsStart + $foundAt + (int) $fm[0][1];
                    }

                    return $paramsStart + $foundAt;
                }

                return $paramsStart;
            }
            $offset = $openPos + 1;
        }

        return null;
    }

    /**
     * Blank comments / string / heredoc-nowdoc bodies while preserving newlines (#28481).
     *
     * Matches {@see AsymmetricVisibilityRewriter} so eval()/string payloads that mention
     * `function __construct(… final …)` are not treated as real declarations.
     */
    private static function blankOpaqueRegions(string $source): string
    {
        $tokens = token_get_all($source);
        $out = '';
        $inHeredoc = false;
        foreach ($tokens as $token) {
            if (is_string($token)) {
                if ($inHeredoc) {
                    $out .= preg_replace('/[^\r\n]/', ' ', $token) ?? $token;
                    continue;
                }
                $out .= $token;
                continue;
            }
            [$id, $text] = $token;
            if (T_START_HEREDOC === $id) {
                $inHeredoc = true;
                $out .= $text;
                continue;
            }
            if (T_END_HEREDOC === $id) {
                $inHeredoc = false;
                $out .= $text;
                continue;
            }
            $opaque = $inHeredoc
                || T_COMMENT === $id
                || T_DOC_COMMENT === $id
                || T_CONSTANT_ENCAPSED_STRING === $id
                || T_ENCAPSED_AND_WHITESPACE === $id;
            if ($opaque) {
                $out .= preg_replace('/[^\r\n]/', ' ', $text) ?? $text;
                continue;
            }
            $out .= $text;
        }

        return $out;
    }

    private static function rewriteParams(string $paramsText): string
    {
        $parts = self::splitTopLevelParams($paramsText);
        if ([] === $parts) {
            return $paramsText;
        }
        $rewritten = [];
        foreach ($parts as $param) {
            $rewritten[] = self::rewriteOneParam($param);
        }

        // Preserve original separators by rebuilding from split segments when possible.
        // splitTopLevelParams returns trimmed bodies; re-join with ", " is fine for parse.
        $result = '';
        $pos = 0;
        $idx = 0;
        $len = strlen($paramsText);
        while ($idx < count($parts)) {
            // Copy leading whitespace / commas between params from original.
            while ($pos < $len && (',' === $paramsText[$pos] || preg_match('/\s/', $paramsText[$pos]))) {
                $result .= $paramsText[$pos];
                ++$pos;
            }
            $original = $parts[$idx];
            $foundAt = strpos($paramsText, $original, $pos);
            if (false === $foundAt) {
                // Fallback: join rewritten only.
                return implode(', ', $rewritten);
            }
            if ($foundAt > $pos) {
                $result .= substr($paramsText, $pos, $foundAt - $pos);
            }
            $result .= $rewritten[$idx];
            $pos = $foundAt + strlen($original);
            ++$idx;
        }
        if ($pos < $len) {
            $result .= substr($paramsText, $pos);
        }

        return $result;
    }

    private static function rewriteOneParam(string $param): string
    {
        if (!self::paramIsFinalPromoted($param)) {
            return $param;
        }
        // Strip standalone `final` modifier tokens; keep visibility / readonly / type / attrs.
        $stripped = (string) preg_replace('/\bfinal\b\s*/i', '', $param);
        $stripped = (string) preg_replace('/\s{2,}/', ' ', $stripped);
        $stripped = ltrim($stripped);
        // Zend: bare `final Type $x` promotes as public.
        if (!preg_match('/\b(public|protected|private)\b/i', $stripped)) {
            $stripped = 'public '.$stripped;
        }
        // Marker must precede visibility so php-parser attaches comments to Param (#16954).
        if (preg_match(self::MARKER_PATTERN, $stripped)) {
            return $stripped;
        }

        return '/*'.self::MARKER.'*/ '.$stripped;
    }

    private static function paramIsFinalPromoted(string $param): bool
    {
        // Exclude `final` that is only inside a string / attribute payload: require final
        // before the `$varname` of the parameter. Visibility is optional (Zend promotes
        // bare `final Type $x` as public).
        $dollar = strpos($param, '$');
        if (false === $dollar) {
            return false;
        }
        $head = substr($param, 0, $dollar);

        return (bool) preg_match('/\bfinal\b/i', $head);
    }

    /**
     * @return list<string>
     */
    private static function splitTopLevelParams(string $paramsText): array
    {
        $params = [];
        $buf = '';
        $depthParen = 0;
        $depthBracket = 0;
        $depthBrace = 0;
        $len = strlen($paramsText);
        $inString = null;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $paramsText[$i];
            if (null !== $inString) {
                $buf .= $ch;
                if ('\\' === $ch && $i + 1 < $len) {
                    $buf .= $paramsText[$i + 1];
                    ++$i;
                    continue;
                }
                if ($ch === $inString) {
                    $inString = null;
                }
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $inString = $ch;
                $buf .= $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depthParen;
                $buf .= $ch;
                continue;
            }
            if (')' === $ch) {
                $depthParen = max(0, $depthParen - 1);
                $buf .= $ch;
                continue;
            }
            if ('[' === $ch) {
                ++$depthBracket;
                $buf .= $ch;
                continue;
            }
            if (']' === $ch) {
                $depthBracket = max(0, $depthBracket - 1);
                $buf .= $ch;
                continue;
            }
            if ('{' === $ch) {
                ++$depthBrace;
                $buf .= $ch;
                continue;
            }
            if ('}' === $ch) {
                $depthBrace = max(0, $depthBrace - 1);
                $buf .= $ch;
                continue;
            }
            if (',' === $ch && 0 === $depthParen && 0 === $depthBracket && 0 === $depthBrace) {
                $trim = trim($buf);
                if ('' !== $trim) {
                    $params[] = $trim;
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $trim = trim($buf);
        if ('' !== $trim) {
            $params[] = $trim;
        }

        return $params;
    }

    private static function extractBalancedParenContent(string $source, int $openPos): ?string
    {
        if ($openPos < 0 || $openPos >= strlen($source) || '(' !== $source[$openPos]) {
            return null;
        }
        $depth = 0;
        $len = strlen($source);
        $inString = null;
        for ($i = $openPos; $i < $len; ++$i) {
            $ch = $source[$i];
            if (null !== $inString) {
                if ('\\' === $ch && $i + 1 < $len) {
                    ++$i;
                    continue;
                }
                if ($ch === $inString) {
                    $inString = null;
                }
                continue;
            }
            if ('"' === $ch || "'" === $ch) {
                $inString = $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depth;
                continue;
            }
            if (')' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return substr($source, $openPos + 1, $i - $openPos - 1);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return list<string>
     */
    private static function commentChunksFromAttributes(array $attributes): array
    {
        $chunks = [];
        if (isset($attributes['comments']) && is_array($attributes['comments'])) {
            foreach ($attributes['comments'] as $comment) {
                if (is_object($comment) && method_exists($comment, 'getText')) {
                    $chunks[] = $comment->getText();
                } elseif (is_string($comment)) {
                    $chunks[] = $comment;
                }
            }
        }
        if (isset($attributes['docComment']) && is_object($attributes['docComment'])
            && method_exists($attributes['docComment'], 'getText')) {
            $chunks[] = $attributes['docComment']->getText();
        }

        return $chunks;
    }
}
