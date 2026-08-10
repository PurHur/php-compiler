<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * mbstring global encoding state (php-src ext/mbstring/mbstring.c MBSTRG; #13100).
 */
final class MbstringState
{
    private const MODE_CHAR = 0;
    private const MODE_NONE = 1;
    private const MODE_LONG = 2;
    private const MODE_ENTITY = 3;

    private static string $internalEncoding = 'UTF-8';

    private static string $httpOutput = 'UTF-8';

    private static string $language = 'neutral';

    /** @var list<string> */
    private static array $httpInputList = ['UTF-8'];

    /** Last encoding detected by mb_parse_str() / GPC handler (MBSTRG(http_input_identify)). */
    private static ?string $httpInputIdentify = null;

    /** @var list<string> */
    private static array $detectOrder = ['ASCII', 'UTF-8'];

    private static int $substituteMode = self::MODE_CHAR;

    private static int $substituteCodepoint = 63;

    /** php-src MBSTRG(illegalchars) — cumulative illegal-byte count for mb_get_info. */
    private static int $illegalChars = 0;

    /** php-src mbstring.http_output_conv_mimetypes default. */
    private static string $httpOutputConvMimetypes = '^(text/|application/xhtml\\+xml)';

    /** php-src MBSTRG(outconv_enabled) for mb_output_handler. */
    private static bool $outconvEnabled = false;

    private static string $regexEncoding = 'UTF-8';

    private static string $regexOptions = 'pr';

    /** mb_ereg_search* cursor string (php-src MBREX(search_str); #20024). */
    private static ?string $searchString = null;

    /** Compiled search pattern text (php-src MBREX(search_re) source). */
    private static ?string $searchPattern = null;

    private static bool $searchCaseInsensitive = false;

    /** Per-compile options override; null = use {@see regexOptions()}. */
    private static ?string $searchOptionsOverride = null;

    private static int $searchPos = 0;

    /** @var array<int, string|false>|null */
    private static ?array $searchRegs = null;

    public static function internalEncoding(): string
    {
        return self::$internalEncoding;
    }

    public static function setInternalEncoding(string $canonical): bool
    {
        self::$internalEncoding = $canonical;
        self::syncHttpInputListFromInternalEncoding();

        return true;
    }

    public static function language(?string $language = null): string|bool
    {
        if (null === $language) {
            return self::$language;
        }
        self::$language = MbstringLanguageRegistry::assertValid($language, 'mb_language', 0);

        return true;
    }

    /**
     * @return list<string>
     */
    public static function httpInputList(): array
    {
        return self::$httpInputList;
    }

    public static function httpInput(?string $type = null): string|array|bool
    {
        if (null === $type) {
            return self::$httpInputIdentify ?? false;
        }
        if (1 !== strlen($type)) {
            throw new \ValueError(
                'mb_http_input(): Argument #1 ($type) must be one of "G", "P", "C", "S", "I", or "L"'
            );
        }
        $letter = strtoupper($type[0]);
        switch ($letter) {
            case 'G':
            case 'P':
            case 'C':
            case 'S':
                // Per-source identify slots (get/post/cookie/string) — unset until GPC handlers land.
                return false;
            case 'I':
                return self::$httpInputList;
            case 'L':
                if ([] === self::$httpInputList) {
                    return false;
                }

                return implode(',', self::$httpInputList);
            default:
                throw new \ValueError(
                    'mb_http_input(): Argument #1 ($type) must be one of "G", "P", "C", "S", "I", or "L"'
                );
        }
    }

    /** @param ?string $encoding Canonical encoding name, or null to clear (empty mb_parse_str). */
    public static function setHttpInputIdentify(?string $encoding): void
    {
        self::$httpInputIdentify = $encoding;
    }

    public static function httpInputIdentify(): ?string
    {
        return self::$httpInputIdentify;
    }

    public static function httpOutput(?string $encoding = null): string|bool
    {
        if (null === $encoding) {
            return self::$httpOutput;
        }
        // php-src mbfl_no_encoding "pass" — identity HTTP output mode (ext/mbstring/mbstring.c).
        if (0 === strcasecmp($encoding, 'pass')) {
            self::$httpOutput = 'pass';

            return true;
        }
        $canonical = MbstringEncodingRegistry::assertValid($encoding, 'mb_http_output', 0);
        self::$httpOutput = $canonical;

        return true;
    }

    public static function illegalChars(): int
    {
        return self::$illegalChars;
    }

    public static function addIllegalChars(int $count): void
    {
        if ($count > 0) {
            self::$illegalChars += $count;
        }
    }

    /**
     * libmbfl mbfl_filt_conv_illegal_output() — bytes in $targetEncoding for one illegal event.
     *
     * @param int|null $codepoint Unicode scalar, or null for MBFL_BAD_INPUT (illegal byte)
     */
    public static function substitutionOutput(string $targetEncoding, ?int $codepoint): string
    {
        $canon = CharsetEngine::canonicalize($targetEncoding) ?? strtoupper($targetEncoding);

        return match (self::$substituteMode) {
            self::MODE_NONE => '',
            self::MODE_LONG => null === $codepoint
                ? self::encodeCodepointIntoTarget(self::$substituteCodepoint, $canon)
                : self::encodeAsciiMarkupIntoTarget('U+'.strtoupper(dechex($codepoint)), $canon),
            self::MODE_ENTITY => null === $codepoint
                ? self::encodeCodepointIntoTarget(self::$substituteCodepoint, $canon)
                : self::encodeAsciiMarkupIntoTarget('&#x'.strtoupper(dechex($codepoint)).';', $canon),
            default => self::encodeCodepointIntoTarget(self::$substituteCodepoint, $canon),
        };
    }

    /**
     * Encode a Unicode scalar into $canon; fall back to '?' then drop (libmbfl illegal_output).
     */
    private static function encodeCodepointIntoTarget(int $codepoint, string $canon): string
    {
        $encoded = self::tryEncodeCodepointIntoTarget($codepoint, $canon);
        if (null !== $encoded) {
            return $encoded;
        }
        if (0x3F !== $codepoint) {
            $encoded = self::tryEncodeCodepointIntoTarget(0x3F, $canon);
            if (null !== $encoded) {
                return $encoded;
            }
        }

        return '';
    }

    private static function tryEncodeCodepointIntoTarget(int $codepoint, string $canon): ?string
    {
        if ('UTF-8' === $canon || 'UTF8' === $canon) {
            return self::codepointToUtf8($codepoint);
        }
        if ('ASCII' === $canon || 'US-ASCII' === $canon) {
            return $codepoint <= 0x7F ? \chr($codepoint) : null;
        }
        if ('ISO-8859-1' === $canon || 'LATIN1' === $canon) {
            return $codepoint <= 0xFF ? \chr($codepoint) : null;
        }
        if ('8BIT' === $canon || 'BINARY' === $canon) {
            return $codepoint <= 0xFF ? \chr($codepoint) : null;
        }
        // UTF-16LE/BE: encode via UTF-8 round-trip through CharsetEngine.
        if (str_starts_with($canon, 'UTF-16')) {
            $utf8 = self::codepointToUtf8($codepoint);
            $out = CharsetEngine::convert('UTF-8', $canon, $utf8);

            return false === $out ? null : $out;
        }

        return null;
    }

    private static function encodeAsciiMarkupIntoTarget(string $ascii, string $canon): string
    {
        if ('UTF-8' === $canon || 'ASCII' === $canon || 'ISO-8859-1' === $canon
            || '8BIT' === $canon || 'BINARY' === $canon || 'US-ASCII' === $canon || 'LATIN1' === $canon) {
            return $ascii;
        }
        $out = CharsetEngine::convert('ASCII', $canon, $ascii);

        return false === $out ? $ascii : $out;
    }

    private static function codepointToUtf8(int $cp): string
    {
        if ($cp <= 0x7F) {
            return \chr($cp);
        }
        if ($cp <= 0x7FF) {
            return \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
        }
        if ($cp <= 0xFFFF) {
            return \chr(0xE0 | ($cp >> 12))
                .\chr(0x80 | (($cp >> 6) & 0x3F))
                .\chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            .\chr(0x80 | (($cp >> 12) & 0x3F))
            .\chr(0x80 | (($cp >> 6) & 0x3F))
            .\chr(0x80 | ($cp & 0x3F));
    }

    public static function httpOutputConvMimetypes(): string
    {
        return self::$httpOutputConvMimetypes;
    }

    public static function outconvEnabled(): bool
    {
        return self::$outconvEnabled;
    }

    public static function setOutconvEnabled(bool $enabled): void
    {
        self::$outconvEnabled = $enabled;
    }

    /**
     * mb_get_info() state dump (php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_get_info); #20014).
     *
     * @return array<string, mixed>|string|int|false|null
     */
    public static function getInfo(string $type = 'all'): array|string|int|false|null
    {
        if (0 === strcasecmp($type, 'all')) {
            $info = [
                'internal_encoding' => self::$internalEncoding,
                'http_output' => self::$httpOutput,
                'http_output_conv_mimetypes' => self::$httpOutputConvMimetypes,
            ];
            $mail = self::mailInfo();
            $info['mail_charset'] = $mail['charset'];
            $info['mail_header_encoding'] = $mail['header'];
            $info['mail_body_encoding'] = $mail['body'];
            $info['illegal_chars'] = self::$illegalChars;
            $info['encoding_translation'] = 'Off';
            $info['language'] = self::$language;
            $info['detect_order'] = self::$detectOrder;
            $info['substitute_character'] = self::substituteCharacter();
            $info['strict_detection'] = 'Off';

            return $info;
        }
        if (0 === strcasecmp($type, 'internal_encoding')) {
            return self::$internalEncoding;
        }
        if (0 === strcasecmp($type, 'http_input')) {
            return null;
        }
        if (0 === strcasecmp($type, 'http_output')) {
            return self::$httpOutput;
        }
        if (0 === strcasecmp($type, 'http_output_conv_mimetypes')) {
            return self::$httpOutputConvMimetypes;
        }
        if (0 === strcasecmp($type, 'mail_charset')) {
            return self::mailInfo()['charset'];
        }
        if (0 === strcasecmp($type, 'mail_header_encoding')) {
            return self::mailInfo()['header'];
        }
        if (0 === strcasecmp($type, 'mail_body_encoding')) {
            return self::mailInfo()['body'];
        }
        if (0 === strcasecmp($type, 'illegal_chars')) {
            return self::$illegalChars;
        }
        if (0 === strcasecmp($type, 'encoding_translation')) {
            return 'Off';
        }
        if (0 === strcasecmp($type, 'language')) {
            return self::$language;
        }
        if (0 === strcasecmp($type, 'detect_order')) {
            return self::$detectOrder;
        }
        if (0 === strcasecmp($type, 'substitute_character')) {
            return self::substituteCharacter();
        }
        if (0 === strcasecmp($type, 'strict_detection')) {
            return 'Off';
        }
        if (0 === strcasecmp($type, 'func_overload')) {
            return false;
        }

        return false;
    }

    /**
     * Mail charset/transfer encodings for mb_get_info (mbfl encoding names).
     *
     * @return array{charset: string, header: string, body: string}
     */
    public static function mailInfo(): array
    {
        $profile = MbstringMailProfile::forLanguage(self::$language);

        return [
            'charset' => $profile['charset'],
            'header' => self::mailEncodingDisplayName($profile['header']),
            'body' => self::mailEncodingDisplayName($profile['body']),
        ];
    }

    private static function mailEncodingDisplayName(string $name): string
    {
        return match (strtolower($name)) {
            'base64' => 'BASE64',
            'quoted-printable' => 'Quoted-Printable',
            '7bit' => '7bit',
            '8bit' => '8bit',
            default => $name,
        };
    }

    /**
     * @return list<string>|bool
     */
    public static function detectOrder(null|string|Variable $order = null): array|bool
    {
        if (null === $order) {
            return self::$detectOrder;
        }
        if ($order instanceof Variable) {
            $order = self::parseDetectOrderVariable($order);
        } elseif (\is_string($order)) {
            $order = MbstringEncodingRegistry::parseOrderList('mb_detect_order', 0, $order);
        }
        self::$detectOrder = $order;

        return true;
    }

  /**
     * @return int|string|bool
     */
    public static function substituteCharacter(null|int|string|Variable $substchar = null): int|string|bool
    {
        if (null === $substchar) {
            return match (self::$substituteMode) {
                self::MODE_NONE => 'none',
                self::MODE_LONG => 'long',
                self::MODE_ENTITY => 'entity',
                default => self::$substituteCodepoint,
            };
        }
        if ($substchar instanceof Variable) {
            $resolved = $substchar->resolveIndirect();
            // Explicit null ≡ omitted arg → getter (php-src Z_PARAM_STR_OR_LONG_OR_NULL; #29919).
            if (Variable::TYPE_NULL === $resolved->type) {
                return self::substituteCharacter(null);
            }

            return self::setSubstituteFromVariable($substchar);
        }
        if (\is_string($substchar)) {
            return self::setSubstituteFromString($substchar);
        }

        return self::setSubstituteFromCodepoint($substchar);
    }

    public static function hashTableFromStringList(array $strings): HashTable
    {
        $ht = new HashTable();
        foreach ($strings as $value) {
            $var = new Variable();
            $var->string($value);
            $ht->append($var);
        }

        return $ht;
    }

    /**
     * @return list<string>
     */
    private static function parseDetectOrderVariable(Variable $var): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $var->type) {
            return MbstringEncodingRegistry::parseOrderList('mb_detect_order', 0, $var->toString());
        }
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(sprintf(
                'mb_detect_order(): Argument #1 ($encoding) must be of type array|string|null, %s given',
                self::typeLabel($var)
            ));
        }

        $order = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
            $elem = $elem->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($elem)) {
                throw new \TypeError(sprintf(
                    'mb_detect_order(): Argument #1 ($encoding) must be of type array|string|null, %s given',
                    EnumCaseSupport::typeNameForVariable($elem)
                ));
            }
            if (Variable::TYPE_STRING !== $elem->type) {
                throw new \TypeError(sprintf(
                    'mb_detect_order(): Argument #1 ($encoding) must be of type array|string|null, %s given',
                    self::typeLabel($elem)
                ));
            }
            $canonical = MbstringEncodingRegistry::resolve($elem->toString());
            if (null === $canonical) {
                throw new \ValueError(sprintf(
                    'mb_detect_order(): Argument #1 ($encoding) contains invalid encoding "%s"',
                    $elem->toString()
                ));
            }
            $order[] = $canonical;
        }
        MbstringEncodingRegistry::assertNonEmptyOrder('mb_detect_order', 0, $order);

        return $order;
    }

    private static function setSubstituteFromVariable(Variable $var): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return self::setSubstituteFromCodepoint($var->toInt());
        }
        if (Variable::TYPE_STRING === $var->type) {
            return self::setSubstituteFromString($var->toString());
        }

        throw new \TypeError(sprintf(
            'mb_substitute_character(): Argument #1 ($substchar) must be of type int|string|null, %s given',
            self::typeLabel($var)
        ));
    }

    private static function setSubstituteFromString(string $value): bool
    {
        if (0 === strcasecmp($value, 'none')) {
            self::$substituteMode = self::MODE_NONE;

            return true;
        }
        if (0 === strcasecmp($value, 'long')) {
            self::$substituteMode = self::MODE_LONG;

            return true;
        }
        if (0 === strcasecmp($value, 'entity')) {
            self::$substituteMode = self::MODE_ENTITY;

            return true;
        }

        throw new \ValueError(
            'mb_substitute_character(): Argument #1 ($substchar) must be "none", "long", "entity" or a valid codepoint'
        );
    }

    private static function setSubstituteFromCodepoint(int $codepoint): bool
    {
        if (!self::isValidCodepoint($codepoint)) {
            throw new \ValueError(
                'mb_substitute_character(): Argument #1 ($substchar) is not a valid codepoint'
            );
        }
        self::$substituteMode = self::MODE_CHAR;
        self::$substituteCodepoint = $codepoint;

        return true;
    }

    private static function isValidCodepoint(int $cp): bool
    {
        if ($cp < 0 || $cp >= 0x110000) {
            return false;
        }
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            return false;
        }

        return true;
    }

    public static function regexEncoding(?string $encoding = null): string|bool
    {
        if (null === $encoding) {
            return self::$regexEncoding;
        }
        self::$regexEncoding = MbstringEncodingRegistry::assertValid($encoding, 'mb_regex_encoding', 0);

        return true;
    }

    public static function regexOptions(?string $options = null): string
    {
        $previous = self::$regexOptions;
        if (null !== $options) {
            self::$regexOptions = $options;
        }

        return $previous;
    }

    public static function searchString(): ?string
    {
        return self::$searchString;
    }

    public static function setSearchString(string $string): void
    {
        self::$searchString = $string;
    }

    public static function searchPattern(): ?string
    {
        return self::$searchPattern;
    }

    public static function searchCaseInsensitive(): bool
    {
        return self::$searchCaseInsensitive;
    }

    public static function searchOptionsOverride(): ?string
    {
        return self::$searchOptionsOverride;
    }

    public static function setSearchPattern(
        string $pattern,
        bool $caseInsensitive,
        ?string $optionsOverride
    ): void {
        self::$searchPattern = $pattern;
        self::$searchCaseInsensitive = $caseInsensitive;
        self::$searchOptionsOverride = $optionsOverride;
    }

    public static function searchPos(): int
    {
        return self::$searchPos;
    }

    public static function setSearchPos(int $pos): void
    {
        self::$searchPos = $pos;
    }

    /**
     * @return array<int, string|false>|null
     */
    public static function searchRegs(): ?array
    {
        return self::$searchRegs;
    }

    /**
     * @param array<int, string|false>|null $regs
     */
    public static function setSearchRegs(?array $regs): void
    {
        self::$searchRegs = $regs;
    }

    private static function syncHttpInputListFromInternalEncoding(): void
    {
        self::$httpInputList = [self::$internalEncoding];
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
