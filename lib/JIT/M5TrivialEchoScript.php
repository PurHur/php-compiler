<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Func;
use PHPCfg\Op\Terminal\Echo_;
use PHPCfg\Op\Terminal\Return_;
use PHPCfg\Op\Type\Void_;
use PHPCfg\Operand\Literal;
use PHPCfg\Script;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Hand-build PHPCfg Script + PHPCompiler Block for gen-0 M5 argv limited shapes
 * (#26756 / re-#23468 / #27426):
 *
 *   <?php
 *   echo "TOKEN\n";
 *
 *   <?php
 *   echo 42;            // decimal literal echo (#27426 peer/native)
 *
 *   <?php
 *   $a = 1 + 2; echo $a;
 *
 * Also accepts a leading preamble of declare(...);, line comments, and block comments
 * (examples/000-HelloWorld/example.php) — still a single double-quoted echo body, integer
 * echo, or the assign-plus-echo shape above (folded to a literal echo of the sum).
 * Avoids NestedJIT of PHPCfg\Parser / Compiler::compileEmitSmoke (mid-BB verify /
 * optimize fatals). Host-verified Block matches compileEmitSmoke output for these shapes.
 */
final class M5TrivialEchoScript
{
    /**
     * @return Script|null null when source is not a supported M5 limited shape
     */
    public static function tryBuild(string $code, string $filename): ?Script
    {
        $trimmed = trim($code);
        // Avoid preg_match under NestedJIT (runtime hang in argv driver — #26756).
        if (!str_starts_with($trimmed, '<?php')) {
            return null;
        }
        $rest = self::stripLeadingPreamble(ltrim(substr($trimmed, 5)));
        $echo = self::tryBuildEchoBody($rest, $filename);
        if (null !== $echo) {
            return $echo;
        }
        $echoInt = self::tryBuildEchoIntBody($rest, $filename);
        if (null !== $echoInt) {
            return $echoInt;
        }

        return self::tryBuildAssignPlusEchoBody($rest, $filename);
    }

    /**
     * Single `echo "…";` body (after preamble strip).
     */
    private static function tryBuildEchoBody(string $rest, string $filename): ?Script
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

        return self::scriptEchoingLiteral($value, $filename);
    }

    /**
     * `echo <unsigned-int>;` → literal echo of decimal digits (#27426).
     */
    private static function tryBuildEchoIntBody(string $rest, string $filename): ?Script
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

        return self::scriptEchoingLiteral((string) $value, $filename);
    }

    /**
     * `$name = <int> + <int>; echo $name;` — fold to literal echo of the sum (#27426 arith).
     * No preg — NestedJIT-safe (#26756).
     */
    private static function tryBuildAssignPlusEchoBody(string $rest, string $filename): ?Script
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
        // Fold at match time — same literal-echo Block path as trivial echo (#27426).
        return self::scriptEchoingLiteral((string) ($left + $right), $filename);
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
        // Reject leading-zero multi-digit (keep scanner strict / NestedJIT-simple).
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

    private static function scriptEchoingLiteral(string $value, string $filename): Script
    {
        $script = new Script();
        $script->functions = [];
        $script->main = new Func('{main}', 0, new Void_(), null);
        $attrs = ['filename' => $filename];
        $script->main->cfg->children[] = new Echo_(new Literal($value), $attrs);
        $script->main->cfg->children[] = new Return_(null, $attrs);

        return $script;
    }

    /**
     * Drop declare / comments before the single echo body (#27426 HelloWorld).
     * No preg — NestedJIT-safe (#26756).
     */
    public static function stripLeadingPreamble(string $rest): string
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
            // declare(strict_types=1); — case-insensitive keyword, then through ';'
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
     * Build a PHPCompiler\Block equivalent to compileEmitSmoke(tryBuild(...)).
     */
    public static function blockFromEchoString(string $value, Script $script): Block
    {
        $block = new Block($script->main->cfg);
        $block->func = $script->main;
        $const = new Variable(Variable::TYPE_STRING);
        $const->string($value);
        $block->constants[0] = $const;
        $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, 0));
        $block->addOpCode(new OpCode(OpCode::TYPE_RETURN_VOID));

        return $block;
    }

    /**
     * parseAndCompile for the functional-smoke / hello-world echo shape (#26756).
     */
    public static function parseAndCompile(string $code, string $filename): ?Block
    {
        $script = self::tryBuild($code, $filename);
        if (null === $script) {
            return null;
        }
        $echo = $script->main->cfg->children[0];
        if (!$echo instanceof Echo_ || !$echo->expr instanceof Literal) {
            return null;
        }
        $block = self::blockFromEchoString((string) $echo->expr->value, $script);
        $block->setScriptPath($filename);
        $block->setCompileSource($code);

        return $block;
    }

    public static function logicalName(): string
    {
        return 'PHPCompiler\\JIT\\M5TrivialEchoScript::parseAndCompile';
    }

    public static function isRegistered(Context $context): bool
    {
        return isset($context->functions[strtolower(self::logicalName())]);
    }

    public static function lookup(Context $context): ?Value
    {
        $lc = strtolower(self::logicalName());

        return $context->functions[$lc] ?? null;
    }
}
