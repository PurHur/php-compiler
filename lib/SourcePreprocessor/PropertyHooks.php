<?php

declare(strict_types=1);

namespace PHPCompiler\SourcePreprocessor;

/**
 * Strip PHP 8.4 property-hook blocks for nikic/php-parser v4 and inject hook methods.
 *
 * php-src: Zend/zend_compile.c property hook lowering (issue #3145).
 */
final class PropertyHooks
{
    private const SET_METHOD_PREFIX = '__phpc_property_set_';
    private const GET_METHOD_PREFIX = '__phpc_property_get_';

    /** @var array<string, array<string, array{set?: string, get?: string}>> lcClass => prop => hook method names */
    private array $registry = [];

    /**
     * @return array{0: string, 1: array<string, array<string, array{set?: string, get?: string}>>}
     */
    public function process(string $code): array
    {
        $this->registry = [];
        $offset = 0;
        $len = strlen($code);
        while ($offset < $len) {
            $classPos = $this->findClassKeyword($code, $offset);
            if (null === $classPos) {
                break;
            }
            $braceOpen = strpos($code, '{', $classPos);
            if (false === $braceOpen) {
                break;
            }
            $className = $this->extractClassName($code, $classPos);
            $span = $this->matchingBraceSpan($code, $braceOpen);
            if (null === $span) {
                $offset = $braceOpen + 1;
                continue;
            }
            [$bodyStart, $bodyEnd] = $span;
            $body = substr($code, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
            $processedBody = $this->processClassBody($body, strtolower($className));
            $code = substr($code, 0, $bodyStart + 1).$processedBody.substr($code, $bodyEnd);
            $offset = $bodyStart + 1 + strlen($processedBody);
        }

        return [$code, $this->registry];
    }

    private function findClassKeyword(string $code, int $from): ?int
    {
        if (preg_match('/\bclass\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\b/', $code, $m, PREG_OFFSET_CAPTURE, $from)) {
            return $m[0][1];
        }

        return null;
    }

    private function extractClassName(string $code, int $classPos): string
    {
        if (preg_match('/\bclass\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\b/', $code, $m, 0, $classPos)) {
            return $m[1];
        }

        return 'class';
    }

    /**
     * @return array{0: int, 1: int}|null [openBracePos, closeBracePos]
     */
    private function matchingBraceSpan(string $code, int $openPos): ?array
    {
        $depth = 0;
        $len = strlen($code);
        $inString = false;
        $stringChar = '';
        for ($i = $openPos; $i < $len; ++$i) {
            $ch = $code[$i];
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('{' === $ch) {
                ++$depth;
            } elseif ('}' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return [$openPos, $i];
                }
            }
        }

        return null;
    }

    private function processClassBody(string $body, string $lcClass): string
    {
        $injections = [];
        $offset = 0;
        $out = '';
        while (preg_match('/\$(\w+)\s*\{/', $body, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $prop = $m[1][0];
            $hookOpen = $m[0][1] + strlen($m[0][0]) - 1;
            $span = $this->matchingBraceSpan($body, $hookOpen);
            if (null === $span) {
                $out .= substr($body, $offset);
                break;
            }
            [$open, $close] = $span;
            $hookSource = substr($body, $open + 1, $close - $open - 1);
            $declStart = $m[0][1];
            $declPrefix = substr($body, $offset, $declStart - $offset);
            $propDeclHead = rtrim(substr($body, $declStart, $hookOpen - $declStart));
            $isStatic = (bool) preg_match('/\bstatic\b/', $declPrefix.$propDeclHead);
            $propDecl = preg_replace('/\s+$/', '', $propDeclHead) ?? $propDeclHead;
            if (!str_ends_with($propDecl, ';')) {
                $propDecl .= ';';
            }
            $out .= $declPrefix.$propDecl;
            [$methods, $usesBacking] = $this->lowerHooks($hookSource, $prop, $lcClass, $isStatic);
            if ([] !== $methods && !$usesBacking) {
                if (!isset($this->registry[$lcClass][$prop])) {
                    $this->registry[$lcClass][$prop] = [];
                }
                $this->registry[$lcClass][$prop]['virtual'] = true;
            }
            $injections = array_merge($injections, $methods);
            $offset = $close + 1;
        }
        $out .= substr($body, $offset);
        if ([] !== $injections) {
            $out .= "\n".implode("\n", $injections)."\n";
        }

        return $out;
    }

    /**
     * @return array{0: list<string>, 1: bool} method source chunks, whether any hook touches backing storage
     */
    private function lowerHooks(string $hookSource, string $prop, string $lcClass, bool $isStatic = false): array
    {
        $methods = [];
        $usesBacking = false;
        $rest = trim($hookSource);
        while ('' !== $rest) {
            if (preg_match('/^get\s*=>\s*/s', $rest)) {
                $rest = preg_replace('/^get\s*=>\s*/', '', $rest, 1) ?? $rest;
                [$expr, $rest] = $this->takeUntilSemicolon($rest);
                $usesBacking = $usesBacking || $this->hookTouchesBacking($expr, $prop, $isStatic);
                $body = '{ return '.$expr.'; }';
                $method = self::GET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, '', $body);
                $this->registerHook($lcClass, $prop, 'get', $method, $isStatic);
                continue;
            }
            if (preg_match('/^get\s*\{/s', $rest)) {
                $rest = preg_replace('/^get\s*/', '', $rest, 1) ?? $rest;
                [$body, $rest] = $this->takeBraceBody($rest);
                $usesBacking = $usesBacking || $this->hookTouchesBacking($body, $prop, $isStatic);
                $method = self::GET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, '', $body);
                $this->registerHook($lcClass, $prop, 'get', $method, $isStatic);
                continue;
            }
            if (preg_match('/^set\s*=>\s*/s', $rest)) {
                $rest = preg_replace('/^set\s*=>\s*/', '', $rest, 1) ?? $rest;
                [$expr, $rest] = $this->takeUntilSemicolon($rest);
                $expr = rtrim($expr);
                $backing = $isStatic ? 'self::$'.$prop : '$this->'.$prop;
                $usesBacking = true;
                $body = '{ '.$backing.' = ('.$expr.'); }';
                $method = self::SET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, '$value', $body);
                $this->registerHook($lcClass, $prop, 'set', $method, $isStatic);
                continue;
            }
            if (preg_match('/^set\s*\(/s', $rest)) {
                $rest = preg_replace('/^set\s*/', '', $rest, 1) ?? $rest;
                if (!preg_match('/^\(([^)]*)\)\s*\{/s', $rest, $pm)) {
                    break;
                }
                $params = trim($pm[1]);
                $rest = substr($rest, strlen($pm[0]) - 1);
                [$body, $rest] = $this->takeBraceBody($rest);
                $usesBacking = $usesBacking || $this->hookTouchesBacking($body, $prop, $isStatic);
                $method = self::SET_METHOD_PREFIX.$prop;
                $methods[] = $this->hookMethodDecl($isStatic, $method, $params, $body);
                $this->registerHook($lcClass, $prop, 'set', $method, $isStatic);
                continue;
            }
            break;
        }

        return [$methods, $usesBacking];
    }

    private function hookTouchesBacking(string $source, string $prop, bool $isStatic): bool
    {
        $pattern = $isStatic
            ? '/\bself::\$'.preg_quote($prop, '/').'\b/'
            : '/\$this->'.preg_quote($prop, '/').'\b/';

        return (bool) preg_match($pattern, $source);
    }

    private function hookMethodDecl(bool $isStatic, string $method, string $params, string $body): string
    {
        $static = $isStatic ? 'static ' : '';
        if ('' !== $params) {
            return "    public {$static}function {$method}({$params}) {$body}";
        }

        return "    public {$static}function {$method}() {$body}";
    }

    /**
     * @param 'get'|'set' $kind
     */
    private function registerHook(string $lcClass, string $prop, string $kind, string $method, bool $isStatic): void
    {
        if (!isset($this->registry[$lcClass][$prop])) {
            $this->registry[$lcClass][$prop] = [];
        }
        $this->registry[$lcClass][$prop][$kind] = $method;
        if ($isStatic) {
            $this->registry[$lcClass][$prop]['static'] = true;
        }
    }

    /**
     * @return array{0: string, 1: string} [expression/statement, remainder after ';']
     */
    private function takeUntilSemicolon(string $source): array
    {
        $source = ltrim($source);
        $len = strlen($source);
        $depthParen = 0;
        $depthBrace = 0;
        $depthBracket = 0;
        $inString = false;
        $stringChar = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $source[$i];
            if ($inString) {
                if ('\\' === $ch) {
                    ++$i;
                    continue;
                }
                if ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch || '\'' === $ch) {
                $inString = true;
                $stringChar = $ch;
                continue;
            }
            if ('(' === $ch) {
                ++$depthParen;
            } elseif (')' === $ch && $depthParen > 0) {
                --$depthParen;
            } elseif ('{' === $ch) {
                ++$depthBrace;
            } elseif ('}' === $ch && $depthBrace > 0) {
                --$depthBrace;
            } elseif ('[' === $ch) {
                ++$depthBracket;
            } elseif (']' === $ch && $depthBracket > 0) {
                --$depthBracket;
            } elseif (';' === $ch && 0 === $depthParen && 0 === $depthBrace && 0 === $depthBracket) {
                $chunk = rtrim(substr($source, 0, $i));

                return [$chunk, ltrim(substr($source, $i + 1))];
            }
        }

        return [rtrim($source), ''];
    }

    /**
     * @return array{0: string, 1: string} [brace block including braces, remainder]
     */
    private function takeBraceBody(string $source): array
    {
        $source = ltrim($source);
        if (!str_starts_with($source, '{')) {
            return ['{ }', ''];
        }
        $span = $this->matchingBraceSpan($source, 0);
        if (null === $span) {
            return ['{ }', ''];
        }
        [$open, $close] = $span;
        $block = substr($source, $open, $close - $open + 1);

        return [$block, ltrim(substr($source, $close + 1))];
    }

    public static function setHookMethodName(string $property): string
    {
        return self::SET_METHOD_PREFIX.$property;
    }

    public static function getHookMethodName(string $property): string
    {
        return self::GET_METHOD_PREFIX.$property;
    }

    public static function propertyNameFromSetHookMethod(string $methodLc): ?string
    {
        $prefix = strtolower(self::SET_METHOD_PREFIX);
        if (!str_starts_with($methodLc, $prefix)) {
            return null;
        }

        return substr($methodLc, strlen($prefix));
    }

    public static function propertyNameFromGetHookMethod(string $methodLc): ?string
    {
        $prefix = strtolower(self::GET_METHOD_PREFIX);
        if (!str_starts_with($methodLc, $prefix)) {
            return null;
        }

        return substr($methodLc, strlen($prefix));
    }
}
