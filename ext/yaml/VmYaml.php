<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamOpenFailure;
use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * YAML 1.1 subset parse/emit (PECL yaml / yaml.c; #6275).
 *
 * Pure PHP — mappings, sequences, scalars, document markers, indent nesting.
 */
final class VmYaml
{
    private const PARSE_WARNING = 'yaml_parse(): An error occurred parsing the input YAML stream';

    /** @return mixed|false */
    public static function parse(string $input, ?Frame $frame = null)
    {
        try {
            return (new YamlSubsetParser($input))->parseDocument();
        } catch (YamlParseException) {
            self::emitWarning($frame, self::PARSE_WARNING);

            return false;
        }
    }

    /** @return mixed|false */
    public static function parseFile(string $filename, ?Frame $frame = null)
    {
        if (!is_file($filename) || !is_readable($filename)) {
            self::emitWarning($frame, 'yaml_parse_file(): Unable to open '.$filename.' for reading');

            return false;
        }
        $raw = file_get_contents($filename);
        if (false === $raw) {
            self::emitWarning($frame, 'yaml_parse_file(): Unable to open '.$filename.' for reading');

            return false;
        }

        return self::parse($raw, $frame);
    }

    /**
     * yaml_parse_url() — read via stream wrappers (PECL yaml.c; #22252).
     *
     * Unlike {@see parseFile()} (IGNORE_URL), allows file:// / data:// and other wrappers
     * via php_stream_open_wrapper + REPORT_ERRORS parity.
     *
     * @return mixed|false
     */
    public static function parseUrl(string $url, ?Frame $frame = null)
    {
        $raw = VmFs::readPathContentsViaOpen($url, $frame?->vmContext);
        if (false === $raw) {
            if (null !== $frame) {
                VmStreamOpenFailure::warnFailedToOpen($frame, 'yaml_parse_url', $url);
            }

            return false;
        }

        return self::parse($raw, $frame);
    }

    /**
     * @param int $encoding libyaml YAML_*_ENCODING (UTF-8 / ANY emit UTF-8; UTF-16 deferred as UTF-8 #27873)
     * @param int $linebreak libyaml YAML_*_BREAK
     */
    public static function emit(Variable $value, int $encoding = 0, int $linebreak = 0): string
    {
        require_once __DIR__.'/YamlConstants.php';
        $body = self::emitValue($value->resolveIndirect(), 0);
        $doc = "---\n".$body.(\str_ends_with($body, "\n") ? '' : "\n")."...\n";
        if (YamlConstants::CR_BREAK === $linebreak) {
            $doc = \str_replace("\n", "\r", $doc);
        } elseif (YamlConstants::CRLN_BREAK === $linebreak) {
            $doc = \str_replace("\n", "\r\n", $doc);
        }
        // Encoding: v1 keeps PHP string UTF-8 bytes for ANY/UTF8; UTF-16* accepted for arity
        // parity but still returns UTF-8 (no BOM) until a real transcoder lands (#27873).
        unset($encoding);

        return $doc;
    }

    public static function emitFile(
        string $filename,
        Variable $value,
        ?Frame $frame = null,
        int $encoding = 0,
        int $linebreak = 0
    ): bool {
        $ok = @\file_put_contents($filename, self::emit($value, $encoding, $linebreak));
        if (false === $ok) {
            self::emitWarning($frame, 'yaml_emit_file(): Failed writing to '.$filename);

            return false;
        }

        return true;
    }

    private static function emitValue(Variable $value, int $indent): string
    {
        switch ($value->type) {
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_BOOLEAN:
                return $value->toBool(null) ? 'true' : 'false';
            case Variable::TYPE_INTEGER:
                return (string) $value->toInt(null);
            case Variable::TYPE_FLOAT:
                $f = $value->toFloat(null);
                if (is_nan($f)) {
                    return '.nan';
                }
                if (is_infinite($f)) {
                    return $f > 0 ? '.inf' : '-.inf';
                }

                return rtrim(rtrim(sprintf('%.14F', $f), '0'), '.') ?: '0';
            case Variable::TYPE_STRING:
                return self::emitString($value->toString(null));
            case Variable::TYPE_ARRAY:
                return self::emitArray($value->toArray(), $indent);
            default:
                return 'null';
        }
    }

    private static function emitString(string $s): string
    {
        if ('' === $s) {
            return "''";
        }
        $needsQuotes = (bool) preg_match('/[\x00-\x1f#:{}\\[\],&*!|>%@`]/', $s)
            || (bool) preg_match('/^\\s|\\s$/', $s)
            || (bool) preg_match('/^(true|false|null|yes|no|on|off|~)$/i', $s)
            || (bool) preg_match('/^-?\\d+(\\.\\d+)?([eE][+-]?\\d+)?$/', $s)
            || str_contains($s, "'")
            || str_contains($s, '"');
        if ($needsQuotes) {
            return "'".str_replace("'", "''", $s)."'";
        }

        return $s;
    }

    private static function emitArray(HashTable $ht, int $indent): string
    {
        $pairs = iterator_to_array($ht->iterateKeyed(true), false);
        if ([] === $pairs) {
            return '[]';
        }
        $pad = str_repeat(' ', $indent);
        $lines = [];
        if ($ht->isPackedList()) {
            foreach ($pairs as [, $item]) {
                $v = $item->resolveIndirect();
                if (Variable::TYPE_ARRAY === $v->type) {
                    $nestedPairs = iterator_to_array($v->toArray()->iterateKeyed(true), false);
                    if ([] !== $nestedPairs) {
                        $lines[] = $pad.'-';
                        foreach (explode("\n", rtrim(self::emitValue($v, $indent + 2), "\n")) as $nl) {
                            $lines[] = str_repeat(' ', $indent + 2).ltrim($nl);
                        }
                        continue;
                    }
                }
                $lines[] = $pad.'- '.self::emitValue($v, $indent + 2);
            }
        } else {
            foreach ($pairs as [$keyVar, $valVar]) {
                $key = $keyVar->resolveIndirect()->toString(null);
                $v = $valVar->resolveIndirect();
                if (Variable::TYPE_ARRAY === $v->type) {
                    $nestedPairs = iterator_to_array($v->toArray()->iterateKeyed(true), false);
                    if ([] !== $nestedPairs) {
                        $lines[] = $pad.$key.':';
                        foreach (explode("\n", rtrim(self::emitValue($v, $indent + 2), "\n")) as $nl) {
                            $lines[] = $nl;
                        }
                        continue;
                    }
                }
                $lines[] = $pad.$key.': '.self::emitValue($v, $indent + 2);
            }
        }

        return implode("\n", $lines);
    }

    private static function emitWarning(?Frame $frame, string $message): void
    {
        if (null === $frame?->vmContext) {
            @\trigger_error($message, \E_WARNING);

            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== ($frame->scriptPath ?? '') ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}

/** @internal */
final class YamlParseException extends \Exception
{
}

/** @internal */
final class YamlSubsetParser
{
    /** @var list<string> */
    private array $lines;
    private int $i = 0;
    private int $n;

    public function __construct(string $input)
    {
        $this->lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $input));
        $this->n = count($this->lines);
    }

    /** @return mixed */
    public function parseDocument()
    {
        $this->skipBlankAndComments();
        if ($this->i >= $this->n) {
            return null;
        }
        if (preg_match('/^---(\s|$)/', $this->lines[$this->i])) {
            ++$this->i;
            $this->skipBlankAndComments();
        }
        if ($this->i >= $this->n) {
            return null;
        }
        $trim = ltrim($this->currentLine());
        if (str_starts_with($trim, '[') || str_starts_with($trim, '{')) {
            return $this->parseFlow($trim);
        }

        return $this->parseBlock($this->indentOf($this->i));
    }

    /** @return mixed */
    private function parseBlock(int $minIndent)
    {
        $this->skipBlankAndComments();
        if ($this->i >= $this->n) {
            return null;
        }
        $indent = $this->indentOf($this->i);
        if ($indent < $minIndent) {
            return null;
        }
        $trim = ltrim($this->currentLine());
        if (str_starts_with($trim, '- ') || '-' === $trim) {
            return $this->parseSequence($indent);
        }
        if (':' === $trim || str_starts_with($trim, ': ')) {
            throw new YamlParseException('Empty mapping key');
        }
        if ($this->looksLikeMapping($trim)) {
            return $this->parseMapping($indent);
        }
        $scalar = $this->parseScalarToken($trim);
        ++$this->i;

        return $scalar;
    }

    /** @return list<mixed> */
    private function parseSequence(int $indent): array
    {
        $out = [];
        while ($this->i < $this->n) {
            $this->skipBlankAndComments();
            if ($this->i >= $this->n) {
                break;
            }
            $lineIndent = $this->indentOf($this->i);
            if ($lineIndent < $indent) {
                break;
            }
            if ($lineIndent > $indent) {
                throw new YamlParseException('Unexpected indentation in sequence');
            }
            $trim = ltrim($this->currentLine());
            if (!str_starts_with($trim, '-')) {
                break;
            }
            $after = substr($trim, 1);
            if ('' !== $after && !str_starts_with($after, ' ')) {
                throw new YamlParseException('Invalid sequence entry');
            }
            $after = ltrim($after);
            ++$this->i;
            if ('' === $after) {
                $out[] = $this->parseBlock($indent + 2);
            } elseif ($this->looksLikeMapping($after)) {
                $out[] = $this->parseInlineMappingAfterDash($after, $indent + 2);
            } elseif (str_starts_with($after, '[') || str_starts_with($after, '{')) {
                $out[] = $this->parseFlow($after);
            } else {
                $out[] = $this->parseScalarToken($after);
            }
        }

        return $out;
    }

    /** @return array<string|int, mixed> */
    private function parseMapping(int $indent): array
    {
        $out = [];
        while ($this->i < $this->n) {
            $this->skipBlankAndComments();
            if ($this->i >= $this->n) {
                break;
            }
            $lineIndent = $this->indentOf($this->i);
            if ($lineIndent < $indent) {
                break;
            }
            if ($lineIndent > $indent) {
                throw new YamlParseException('Unexpected indentation in mapping');
            }
            $trim = ltrim($this->currentLine());
            if (str_starts_with($trim, '-') || !$this->looksLikeMapping($trim)) {
                break;
            }
            [$key, $rest] = $this->splitMappingLine($trim);
            ++$this->i;
            if ('' === $rest) {
                $out[$key] = $this->parseBlock($indent + 2);
            } elseif (str_starts_with($rest, '[') || str_starts_with($rest, '{')) {
                $out[$key] = $this->parseFlow($rest);
            } else {
                $out[$key] = $this->parseScalarToken($rest);
            }
        }

        return $out;
    }

    /** @return array<string|int, mixed> */
    private function parseInlineMappingAfterDash(string $after, int $childIndent): array
    {
        [$key, $rest] = $this->splitMappingLine($after);
        $map = [];
        if ('' === $rest) {
            $map[$key] = $this->parseBlock($childIndent);
        } else {
            $map[$key] = $this->parseScalarToken($rest);
        }
        while ($this->i < $this->n) {
            $this->skipBlankAndComments();
            if ($this->i >= $this->n) {
                break;
            }
            $lineIndent = $this->indentOf($this->i);
            if ($lineIndent < $childIndent) {
                break;
            }
            if ($lineIndent > $childIndent) {
                throw new YamlParseException('Unexpected indentation');
            }
            $trim = ltrim($this->currentLine());
            if (!$this->looksLikeMapping($trim)) {
                break;
            }
            [$k, $r] = $this->splitMappingLine($trim);
            ++$this->i;
            $map[$k] = '' === $r ? $this->parseBlock($childIndent + 2) : $this->parseScalarToken($r);
        }

        return $map;
    }

    /** @return array{0: string, 1: string} */
    private function splitMappingLine(string $trim): array
    {
        if (preg_match('/^("(?:\\\\.|[^"\\\\])*"|\'(?:[^\']|\'\')*\')\s*:\s*(.*)$/', $trim, $m)) {
            $key = $this->parseScalarToken($m[1]);

            return [(string) $key, $m[2]];
        }
        $pos = strpos($trim, ':');
        if (false === $pos) {
            throw new YamlParseException('Missing mapping colon');
        }
        $keyRaw = rtrim(substr($trim, 0, $pos));
        $rest = ltrim(substr($trim, $pos + 1));
        if ('' === $keyRaw) {
            throw new YamlParseException('Empty mapping key');
        }

        return [(string) $this->parseScalarToken($keyRaw), $rest];
    }

    private function looksLikeMapping(string $trim): bool
    {
        if ('' === $trim || str_starts_with($trim, '-')) {
            return false;
        }
        if (preg_match('/^("(?:\\\\.|[^"\\\\])*"|\'(?:[^\']|\'\')*\')\s*:/', $trim)) {
            return true;
        }
        $pos = strpos($trim, ':');

        return false !== $pos && 0 !== $pos;
    }

    /** @return mixed */
    private function parseScalarToken(string $token)
    {
        $token = rtrim($token);
        if ('' === $token) {
            return null;
        }
        if (!str_starts_with($token, '"') && !str_starts_with($token, "'")) {
            if (preg_match('/^(.*?)\s+#.*$/', $token, $m)) {
                $token = rtrim($m[1]);
            }
        }
        if ('|' === $token || '>' === $token) {
            throw new YamlParseException('Block scalars not implemented in this subset');
        }
        if (str_starts_with($token, "'")) {
            if (!preg_match("/^'(.*)'$/s", $token, $m)) {
                throw new YamlParseException('Unterminated single-quoted string');
            }

            return str_replace("''", "'", $m[1]);
        }
        if (str_starts_with($token, '"')) {
            if (!preg_match('/^"(.*)"$/s', $token, $m)) {
                throw new YamlParseException('Unterminated double-quoted string');
            }

            return stripcslashes($m[1]);
        }
        $lower = strtolower($token);
        if (\in_array($lower, ['null', '~'], true)) {
            return null;
        }
        if (\in_array($lower, ['true', 'yes', 'on'], true)) {
            return true;
        }
        if (\in_array($lower, ['false', 'no', 'off'], true)) {
            return false;
        }
        if (\in_array($lower, ['.nan', '.inf', '+.inf', '-.inf'], true)) {
            return '.nan' === $lower ? NAN : (str_starts_with($lower, '-') ? -INF : INF);
        }
        if (preg_match('/^-?0x[0-9a-fA-F]+$/', $token)) {
            return intval($token, 16);
        }
        if (preg_match('/^-?\d+$/', $token)) {
            return (int) $token;
        }
        if (preg_match('/^-?\d+\.\d+([eE][+-]?\d+)?$/', $token) || preg_match('/^-?\d+[eE][+-]?\d+$/', $token)) {
            return (float) $token;
        }

        return $token;
    }

    /** @return mixed */
    private function parseFlow(string $text)
    {
        $parser = new YamlFlowParser(trim($text));
        $value = $parser->parse();
        if (!$parser->atEnd()) {
            throw new YamlParseException('Trailing junk in flow node');
        }

        return $value;
    }

    private function skipBlankAndComments(): void
    {
        while ($this->i < $this->n) {
            $trim = trim($this->lines[$this->i]);
            if ('' === $trim || str_starts_with($trim, '#') || str_starts_with($trim, '...')) {
                ++$this->i;
                continue;
            }
            break;
        }
    }

    private function currentLine(): string
    {
        return $this->lines[$this->i] ?? '';
    }

    private function indentOf(int $idx): int
    {
        $line = $this->lines[$idx] ?? '';
        if (preg_match('/^( *)/', $line, $m)) {
            return strlen($m[1]);
        }

        return 0;
    }
}

/** @internal */
final class YamlFlowParser
{
    private string $s;
    private int $i = 0;
    private int $n;

    public function __construct(string $s)
    {
        $this->s = $s;
        $this->n = strlen($s);
    }

    public function atEnd(): bool
    {
        $this->skipWs();

        return $this->i >= $this->n;
    }

    /** @return mixed */
    public function parse()
    {
        $this->skipWs();
        if ($this->i >= $this->n) {
            throw new YamlParseException('Empty flow');
        }
        $c = $this->s[$this->i];
        if ('[' === $c) {
            return $this->parseSeq();
        }
        if ('{' === $c) {
            return $this->parseMap();
        }

        return $this->parseScalar();
    }

    /** @return list<mixed> */
    private function parseSeq(): array
    {
        ++$this->i;
        $out = [];
        $this->skipWs();
        if ($this->i < $this->n && ']' === $this->s[$this->i]) {
            ++$this->i;

            return $out;
        }
        while (true) {
            $out[] = $this->parse();
            $this->skipWs();
            if ($this->i >= $this->n) {
                throw new YamlParseException('Unterminated flow sequence');
            }
            if (',' === $this->s[$this->i]) {
                ++$this->i;
                $this->skipWs();
                continue;
            }
            if (']' === $this->s[$this->i]) {
                ++$this->i;
                break;
            }
            throw new YamlParseException('Expected , or ] in flow sequence');
        }

        return $out;
    }

    /** @return array<string|int, mixed> */
    private function parseMap(): array
    {
        ++$this->i;
        $out = [];
        $this->skipWs();
        if ($this->i < $this->n && '}' === $this->s[$this->i]) {
            ++$this->i;

            return $out;
        }
        while (true) {
            $key = $this->parse();
            $this->skipWs();
            if ($this->i >= $this->n || ':' !== $this->s[$this->i]) {
                throw new YamlParseException('Expected : in flow mapping');
            }
            ++$this->i;
            $out[\is_string($key) || \is_int($key) ? $key : (string) $key] = $this->parse();
            $this->skipWs();
            if ($this->i >= $this->n) {
                throw new YamlParseException('Unterminated flow mapping');
            }
            if (',' === $this->s[$this->i]) {
                ++$this->i;
                $this->skipWs();
                continue;
            }
            if ('}' === $this->s[$this->i]) {
                ++$this->i;
                break;
            }
            throw new YamlParseException('Expected , or } in flow mapping');
        }

        return $out;
    }

    /** @return mixed */
    private function parseScalar()
    {
        $this->skipWs();
        if ($this->i >= $this->n) {
            throw new YamlParseException('Expected scalar');
        }
        $c = $this->s[$this->i];
        if ("'" === $c || '"' === $c) {
            return $this->parseQuoted($c);
        }
        $start = $this->i;
        while ($this->i < $this->n) {
            $ch = $this->s[$this->i];
            if (\in_array($ch, [',', ']', '}', ':'], true)) {
                break;
            }
            ++$this->i;
        }
        $token = trim(substr($this->s, $start, $this->i - $start));
        if ('' === $token) {
            return null;
        }
        $lower = strtolower($token);
        if (\in_array($lower, ['null', '~'], true)) {
            return null;
        }
        if (\in_array($lower, ['true', 'yes', 'on'], true)) {
            return true;
        }
        if (\in_array($lower, ['false', 'no', 'off'], true)) {
            return false;
        }
        if (preg_match('/^-?\d+$/', $token)) {
            return (int) $token;
        }
        if (preg_match('/^-?\d+\.\d+([eE][+-]?\d+)?$/', $token)) {
            return (float) $token;
        }

        return $token;
    }

    private function parseQuoted(string $q): string
    {
        ++$this->i;
        $out = '';
        while ($this->i < $this->n) {
            $ch = $this->s[$this->i];
            if ($ch === $q) {
                if ("'" === $q && ($this->i + 1) < $this->n && "'" === $this->s[$this->i + 1]) {
                    $out .= "'";
                    $this->i += 2;
                    continue;
                }
                ++$this->i;

                return $out;
            }
            if ('\\' === $ch && '"' === $q) {
                ++$this->i;
                if ($this->i >= $this->n) {
                    throw new YamlParseException('Bad escape');
                }
                $esc = $this->s[$this->i];
                $out .= match ($esc) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '\\', '"' => $esc,
                    default => $esc,
                };
                ++$this->i;
                continue;
            }
            $out .= $ch;
            ++$this->i;
        }
        throw new YamlParseException('Unterminated quoted string');
    }

    private function skipWs(): void
    {
        while ($this->i < $this->n && ctype_space($this->s[$this->i])) {
            ++$this->i;
        }
    }
}
