<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Lightweight peer for PHPCfg\Parser::$astParser / $astTraverser / $magicStringResolver
 * under M5 C-floor (#27426).
 *
 * Allocated instead of PhpParser\Parser\Php7 — class registration of the 177KB generated
 * parser during emit SEGVd argv rebuild at c:main_before_php. This peer implements the
 * {@see \PhpParser\Parser} surface used by PHPCfg\Parser::parse plus identity traverser /
 * magic-resolver stubs so FORCE_PARSER NestedJIT can call through without null peers.
 *
 * {@see parse()} hand-builds PhpParser AST for:
 * - echo string / assign+plus+echo (same as {@see M5TrivialEchoScript})
 * - `echo <unsigned-int>;` — also on {@see M5TrivialEchoNative} / Script (#27426);
 *   Zend peer→PHPCfg Script proves AST path; NestedJIT peer AST ctors still abort
 *
 * Broader PHP still needs NestedJIT of real Php7 (or an expanded hand-builder) —
 * not emit-time Php7 allocation.
 */
final class M5ParserAstPeer implements \PhpParser\Parser
{
    /**
     * @param string                       $code
     * @param \PhpParser\ErrorHandler|null $errorHandler
     *
     * @return \PhpParser\Node\Stmt[]|null
     */
    public function parse(string $code, ?\PhpParser\ErrorHandler $errorHandler = null): ?array
    {
        $trimmed = trim($code);
        if (!str_starts_with($trimmed, '<?php')) {
            return null;
        }
        // Local preamble strip — NestedJIT must not call M5TrivialEchoScript (#27426).
        $rest = self::stripLeadingPreamble(ltrim(substr($trimmed, 5)));
        $echo = self::tryEchoStringAst($rest);
        if (null !== $echo) {
            return $echo;
        }
        $echoInt = self::tryEchoIntAst($rest);
        if (null !== $echoInt) {
            return $echoInt;
        }

        return self::tryAssignPlusEchoAst($rest);
    }

    /**
     * Drop declare / comments before the echo or assign body (#27426 HelloWorld).
     * No preg — NestedJIT-safe (#26756). Mirrors {@see M5TrivialEchoScript::stripLeadingPreamble}.
     */
    private static function stripLeadingPreamble(string $rest): string
    {
        while ($rest !== '') {
            $rest = ltrim($rest);
            if ($rest === '') {
                break;
            }
            if (str_starts_with($rest, '//')) {
                $nl = strpos($rest, "\n");
                $rest = false === $nl ? '' : substr($rest, $nl + 1);
                continue;
            }
            if (str_starts_with($rest, '#')) {
                $nl = strpos($rest, "\n");
                $rest = false === $nl ? '' : substr($rest, $nl + 1);
                continue;
            }
            if (str_starts_with($rest, '/*')) {
                $end = strpos($rest, '*/');
                if (false === $end) {
                    return '';
                }
                $rest = substr($rest, $end + 2);
                continue;
            }
            if (strncasecmp($rest, 'declare', 7) === 0) {
                $after = substr($rest, 7);
                if ($after === '') {
                    return '';
                }
                $c0 = $after[0];
                if ($c0 !== '(' && $c0 !== ' ' && $c0 !== "\t" && $c0 !== "\n" && $c0 !== "\r") {
                    break;
                }
                $semi = strpos($rest, ';');
                if (false === $semi) {
                    return '';
                }
                $rest = substr($rest, $semi + 1);
                continue;
            }
            break;
        }

        return ltrim($rest);
    }

    /**
     * Identity traverse for C-floor astTraverser peer (#27426).
     *
     * @param array<\PhpParser\Node> $nodes
     *
     * @return array<\PhpParser\Node>
     */
    public function traverse(array $nodes): array
    {
        return $nodes;
    }

    /** No-op — C-floor skips PHPCfg\Parser ctor visitor wiring (#27426). */
    public function addVisitor(object $visitor): void
    {
    }

    /** No-op magic-string begin — limited shapes have no __FILE__ / __DIR__ (#27426). */
    public function beginCompilationUnit(string $fileName): void
    {
    }

    /**
     * @return list<\PhpParser\Node\Stmt>|null
     */
    private static function tryEchoStringAst(string $rest): ?array
    {
        if (!str_starts_with($rest, 'echo')) {
            return null;
        }
        $rest = ltrim(substr($rest, 4));
        if ($rest === '' || $rest[0] !== '"') {
            return null;
        }
        $i = 1;
        $len = strlen($rest);
        $value = '';
        while ($i < $len) {
            $ch = $rest[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $next = $rest[$i + 1];
                if ($next === 'n') {
                    $value .= "\n";
                } elseif ($next === 't') {
                    $value .= "\t";
                } elseif ($next === '\\' || $next === '"') {
                    $value .= $next;
                } else {
                    $value .= $next;
                }
                $i += 2;
                continue;
            }
            if ($ch === '"') {
                break;
            }
            $value .= $ch;
            ++$i;
        }
        if ($i >= $len || $rest[$i] !== '"') {
            return null;
        }
        $tail = trim(substr($rest, $i + 1));
        if ($tail !== ';') {
            return null;
        }

        return [
            new \PhpParser\Node\Stmt\Echo_([
                new \PhpParser\Node\Scalar\String_($value),
            ]),
        ];
    }

    /**
     * `echo <unsigned-int>;` → Echo AST (#27426). Same shape as
     * {@see M5TrivialEchoScript::tryBuild} / Native extract — Zend peer proves
     * PhpParser AST → PHPCfg\Script; NestedJIT of this body still aborts on Node ctors.
     *
     * @return list<\PhpParser\Node\Stmt>|null
     */
    private static function tryEchoIntAst(string $rest): ?array
    {
        if (!str_starts_with($rest, 'echo')) {
            return null;
        }
        $i = self::skipWs($rest, 4);
        $value = self::scanUnsignedInt($rest, $i);
        if (null === $value) {
            return null;
        }
        $i = self::skipWs($rest, $i);
        if ($i >= strlen($rest) || $rest[$i] !== ';') {
            return null;
        }
        $i = self::skipWs($rest, $i + 1);
        if ($i !== strlen($rest)) {
            return null;
        }

        return [
            new \PhpParser\Node\Stmt\Echo_([
                new \PhpParser\Node\Scalar\LNumber($value),
            ]),
        ];
    }

    /**
     * `$name = <int> + <int>; echo $name;` → Assign + Echo AST (#27426).
     *
     * @return list<\PhpParser\Node\Stmt>|null
     */
    private static function tryAssignPlusEchoAst(string $rest): ?array
    {
        if ($rest === '' || $rest[0] !== '$') {
            return null;
        }
        $i = 1;
        $len = strlen($rest);
        $name = self::scanIdent($rest, $i);
        if (null === $name) {
            return null;
        }
        $i = self::skipWs($rest, $i);
        if ($i >= $len || $rest[$i] !== '=') {
            return null;
        }
        $i = self::skipWs($rest, $i + 1);
        $left = self::scanUnsignedInt($rest, $i);
        if (null === $left) {
            return null;
        }
        $i = self::skipWs($rest, $i);
        if ($i >= $len || $rest[$i] !== '+') {
            return null;
        }
        $i = self::skipWs($rest, $i + 1);
        $right = self::scanUnsignedInt($rest, $i);
        if (null === $right) {
            return null;
        }
        $i = self::skipWs($rest, $i);
        if ($i >= $len || $rest[$i] !== ';') {
            return null;
        }
        $i = self::skipWs($rest, $i + 1);
        if ($i + 4 > $len || substr($rest, $i, 4) !== 'echo') {
            return null;
        }
        $i = self::skipWs($rest, $i + 4);
        if ($i >= $len || $rest[$i] !== '$') {
            return null;
        }
        ++$i;
        $echoName = self::scanIdent($rest, $i);
        if (null === $echoName || $echoName !== $name) {
            return null;
        }
        $i = self::skipWs($rest, $i);
        if ($i >= $len || $rest[$i] !== ';') {
            return null;
        }
        $i = self::skipWs($rest, $i + 1);
        if ($i !== $len) {
            return null;
        }

        $var = new \PhpParser\Node\Expr\Variable($name);

        return [
            new \PhpParser\Node\Stmt\Expression(
                new \PhpParser\Node\Expr\Assign(
                    $var,
                    new \PhpParser\Node\Expr\BinaryOp\Plus(
                        new \PhpParser\Node\Scalar\LNumber($left),
                        new \PhpParser\Node\Scalar\LNumber($right)
                    )
                )
            ),
            new \PhpParser\Node\Stmt\Echo_([
                new \PhpParser\Node\Expr\Variable($name),
            ]),
        ];
    }

    /** @param-out int $i */
    private static function scanIdent(string $s, int &$i): ?string
    {
        $len = strlen($s);
        if ($i >= $len) {
            return null;
        }
        $c0 = $s[$i];
        if (!(($c0 >= 'a' && $c0 <= 'z') || ($c0 >= 'A' && $c0 <= 'Z') || $c0 === '_')) {
            return null;
        }
        $start = $i;
        ++$i;
        while ($i < $len) {
            $c = $s[$i];
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z')
                || ($c >= '0' && $c <= '9') || $c === '_') {
                ++$i;
                continue;
            }
            break;
        }

        return substr($s, $start, $i - $start);
    }

    /** @param-out int $i */
    private static function scanUnsignedInt(string $s, int &$i): ?int
    {
        $len = strlen($s);
        if ($i >= $len || $s[$i] < '0' || $s[$i] > '9') {
            return null;
        }
        $start = $i;
        while ($i < $len && $s[$i] >= '0' && $s[$i] <= '9') {
            ++$i;
        }
        $raw = substr($s, $start, $i - $start);
        if (strlen($raw) > 1 && $raw[0] === '0') {
            return null;
        }
        if (strlen($raw) > 18) {
            return null;
        }

        return (int) $raw;
    }

    private static function skipWs(string $s, int $i): int
    {
        $len = strlen($s);
        while ($i < $len) {
            $c = $s[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                ++$i;
                continue;
            }
            break;
        }

        return $i;
    }
}
