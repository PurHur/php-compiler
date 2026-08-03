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
 * Hand-build PHPCfg Script + PHPCompiler Block for the gen-0 functional-smoke shape
 * (#26756 / re-#23468 / #27426):
 *
 *   <?php
 *   echo "TOKEN\n";
 *
 * Also accepts a leading preamble of declare(...);, line comments, and block comments
 * (examples/000-HelloWorld/example.php) — still a single double-quoted echo body.
 * Avoids NestedJIT of PHPCfg\Parser / Compiler::compileEmitSmoke (mid-BB verify /
 * optimize fatals). Host-verified Block matches compileEmitSmoke output for this shape.
 */
final class M5TrivialEchoScript
{
    /**
     * @return Script|null null when source is not a single echo of a double-quoted string
     */
    public static function tryBuild(string $code, string $filename): ?Script
    {
        $trimmed = trim($code);
        // Avoid preg_match under NestedJIT (runtime hang in argv driver — #26756).
        if (!str_starts_with($trimmed, '<?php')) {
            return null;
        }
        $rest = self::stripLeadingPreamble(ltrim(substr($trimmed, 5)));
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
