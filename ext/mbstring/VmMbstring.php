<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\ext\standard\HtmlEntityTable;
use PHPCompiler\ext\standard\mail as MailBuiltin;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmPregMatches;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Shared mbstring VM helpers (php-src ext/mbstring/mbstring.c; #7014, #3239).
 */
final class VmMbstring
{
    public const MB_LTRIM = 1;
    public const MB_RTRIM = 2;
    public const MB_BOTH_TRIM = 3;

    /** @var list<int> php-src ext/mbstring/mbstring.c mb_trim_default_chars */
    private const DEFAULT_TRIM_CODEPOINTS = [
        0x20, 0x0C, 0x0A, 0x0D, 0x09, 0x0B, 0x00, 0xA0, 0x1680,
        0x2000, 0x2001, 0x2002, 0x2003, 0x2004, 0x2005, 0x2006, 0x2007,
        0x2008, 0x2009, 0x200A, 0x2028, 0x2029, 0x202F, 0x205F, 0x3000,
        0x85, 0x180E,
    ];

    public static function coerceModeArg(Variable $var, string $function, int $argIndex = 1): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($mode) must be of type int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($mode) must be of type int, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return self::validateMode($var->toInt(), $function, $argIndex);
    }

    public static function coerceEncodingArg(
        Variable $var,
        string $function,
        int $argIndex = 2,
        string $default = 'UTF-8'
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return $default;
        }

        return self::coerceEncodingString($var, $function, $argIndex);
    }

    /** php-src mbfl_name2encoding — optional ?string encoding with ValueError on unknown names (#4405). */
    public static function resolveValidatedEncodingArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $default
    ): string {
        $encoding = self::coerceEncodingArg($var, $function, $argIndex, $default);

        return MbstringEncodingRegistry::assertValid($encoding, $function, $argIndex);
    }

    /**
     * mb_strlen() character count (php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_strlen); #4405).
     *
     * UTF-8: each illegal byte is one character (libmbfl), matching mb_get_substr unit count (#28629).
     */
    public static function strlen(string $string, string $encoding): int
    {
        if ('UTF-8' === $encoding) {
            return \count(self::utf8MbflCharUnits($string));
        }
        if ('ASCII' === $encoding || '8BIT' === $encoding || 'ISO-8859-1' === $encoding) {
            return VmString::byteLength($string);
        }

        throw new \LogicException(
            'mb_strlen() requires mbstring for encoding '.$encoding.' in this compiler build'
        );
    }

    public static function coerceEncodingString(Variable $var, string $function, int $argIndex = 2): string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($encoding) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($encoding) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    /** php-src mbfl_name2encoding — mbstring metadata builtins (#13100). */
    public static function coerceMbEncodingNameArg(Variable $var, string $function, int $argIndex = 0): string
    {
        $name = self::coerceEncodingString($var, $function, $argIndex);

        return MbstringEncodingRegistry::assertValid($name, $function, $argIndex);
    }

    public static function coerceLanguageArg(Variable $var, string $function, int $argIndex = 0): string
    {
        $var = $var->resolveIndirect();
        // Caller must treat TYPE_NULL as getter (Z_PARAM_STR_OR_NULL / mbstring.stub.php ?string).
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($language) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($language) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    public static function coerceOptionalHttpInputTypeArg(
        Variable $var,
        string $function,
        int $argIndex = 0
    ): ?string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($type) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($type) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    public static function coerceGetInfoTypeArg(Variable $var, string $function, int $argIndex = 0): string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($type) must be of type string, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($type) must be of type string, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    public static function coerceOutputHandlerStringArg(
        Variable $var,
        string $function,
        int $argIndex = 0
    ): string {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($string) must be of type string, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($string) must be of type string, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    public static function coerceOutputHandlerStatusArg(
        Variable $var,
        string $function,
        int $argIndex = 1
    ): int {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($status) must be of type int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($status) must be of type int, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toInt();
    }

    /**
     * Assign mb_get_info() result onto a VM return slot (#20014).
     *
     * @param array<string, mixed>|string|int|false|null $result
     */
    public static function assignGetInfoResult(Variable $returnVar, array|string|int|false|null $result): void
    {
        if (null === $result) {
            $returnVar->null();

            return;
        }
        if (false === $result) {
            $returnVar->bool(false);

            return;
        }
        if (\is_int($result)) {
            $returnVar->int($result);

            return;
        }
        if (\is_string($result)) {
            $returnVar->string($result);

            return;
        }
        $returnVar->array(self::getInfoToHashTable($result));
    }

    /**
     * @param array<string, mixed> $info
     */
    public static function getInfoToHashTable(array $info): HashTable
    {
        $ht = new HashTable();
        foreach ($info as $key => $value) {
            $slot = new Variable();
            if (\is_array($value)) {
                $slot->array(MbstringState::hashTableFromStringList($value));
            } elseif (\is_int($value)) {
                $slot->int($value);
            } elseif (\is_bool($value)) {
                $slot->bool($value);
            } else {
                $slot->string((string) $value);
            }
            $ht->add((string) $key, $slot);
        }

        return $ht;
    }

    /**
     * mb_output_handler() — convert buffer chunk to http_output encoding
     * (php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_output_handler); #20014).
     *
     * VM OB callbacks currently pass PHP_OUTPUT_HANDLER_END only ({@see VmObOutput});
     * treat END-only as a one-shot START|END when conversion is not yet enabled so
     * `ob_start('mb_output_handler')` still converts like a web SAPI with a matching
     * Content-Type / default mimetype.
     */
    public static function outputHandler(string $string, int $status): string
    {
        $httpOutput = MbstringState::httpOutput();
        if (0 === strcasecmp($httpOutput, 'pass')) {
            return $string;
        }

        $start = 0 !== ($status & \PHP_OUTPUT_HANDLER_START);
        $end = 0 !== ($status & (\PHP_OUTPUT_HANDLER_END | \PHP_OUTPUT_HANDLER_FINAL));

        if ($start) {
            MbstringState::setOutconvEnabled(true);
        } elseif ($end && !MbstringState::outconvEnabled()) {
            // Single-shot flush without START (VM ob_start string callback).
            MbstringState::setOutconvEnabled(true);
        }

        if (!MbstringState::outconvEnabled()) {
            return $string;
        }

        $from = MbstringState::internalEncoding();
        if (0 === strcasecmp($from, $httpOutput)) {
            $converted = $string;
        } else {
            $converted = self::convertEncoding($string, $httpOutput, $from);
            if (false === $converted) {
                $converted = $string;
            }
        }

        if ($end) {
            MbstringState::setOutconvEnabled(false);
        }

        return $converted;
    }

    public static function validateMode(int $mode, string $function, int $argIndex = 1): int
    {
        if ($mode < MbstringConstants::MB_CASE_UPPER || $mode > MbstringConstants::MB_CASE_FOLD_SIMPLE) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($mode) must be one of the MB_CASE_* constants',
                $function,
                $argIndex + 1
            ));
        }

        return $mode;
    }

    /**
     * php-src / libmbfl HTML-ENTITIES (aliases HTML / html; #11212, #22631, #28983).
     *
     * Not htmlentities(): ASCII (incl. <>&) stays literal.
     */
    public static function isHtmlEntitiesEncoding(string $encoding): bool
    {
        return 'HTML-ENTITIES' === (MbstringEncodingRegistry::resolve($encoding) ?? '');
    }

    /**
     * php-src / libmbfl Base64 transfer encoding (deprecated in 8.2+; #28980).
     *
     * Listed by mb_list_encodings(); mb_convert_encoding routes through base64_encode/decode.
     */
    public static function isBase64Encoding(string $encoding): bool
    {
        return 'BASE64' === (MbstringEncodingRegistry::resolve($encoding) ?? '');
    }

    /**
     * php-src / libmbfl Quoted-Printable transfer encoding (deprecated in 8.2+; #28982).
     *
     * Aliases: qprint / QPrint / quoted-printable (registry + fuzzy resolve).
     * Encode matches mb_wchar_to_qprint (soft wrap at 72); decode uses quoted_printable_decode.
     */
    public static function isQuotedPrintableEncoding(string $encoding): bool
    {
        return 'Quoted-Printable' === (MbstringEncodingRegistry::resolve($encoding) ?? '');
    }

    /**
     * Pseudo-encodings accepted by mb_convert_encoding() beyond CharsetEngine charsets.
     *
     * UUENCODE remains sibling #28981 — listed for aliases/lists (#28983) but not converted yet.
     */
    public static function isMbConvertPseudoEncoding(string $encoding): bool
    {
        return self::isHtmlEntitiesEncoding($encoding)
            || self::isBase64Encoding($encoding)
            || self::isQuotedPrintableEncoding($encoding);
    }

    /**
     * mb_detect_encoding() — guess byte-string encoding (php-src ext/mbstring/mbstring.c; #3075).
     *
     * @param list<string>|null $encodingList
     */
    public static function detectEncoding(
        string $string,
        ?array $encodingList = null,
        bool $strict = false
    ): string|false {
        $order = $encodingList ?? MbstringState::detectOrder();
        if (\in_array('UTF-8', $order, true) && VmString::isValidUtf8($string)) {
            if (!self::isAsciiByteString($string)) {
                return 'UTF-8';
            }
            $utf8Pos = \array_search('UTF-8', $order, true);
            $asciiPos = \array_search('ASCII', $order, true);
            if (false === $asciiPos || (false !== $utf8Pos && $utf8Pos < $asciiPos)) {
                return 'UTF-8';
            }
        }
        foreach ($order as $encoding) {
            if ('UTF-8' === $encoding) {
                continue;
            }
            if (self::stringMatchesEncoding($string, $encoding, $strict)) {
                return $encoding;
            }
        }
        if (\in_array('UTF-8', $order, true) && VmString::isValidUtf8($string)) {
            return 'UTF-8';
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function coerceDetectEncodingListArg(
        Variable $var,
        string $function,
        int $argIndex = 1
    ): array {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return MbstringState::detectOrder();
        }
        if (Variable::TYPE_STRING === $var->type) {
            return MbstringEncodingRegistry::parseOrderList($function, $argIndex, $var->toString());
        }
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($encodings) must be of type array|string|null, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        $order = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
            $elem = $elem->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($elem)) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #%d ($encodings) must be of type array|string|null, %s given',
                    $function,
                    $argIndex + 1,
                    EnumCaseSupport::typeNameForVariable($elem)
                ));
            }
            if (Variable::TYPE_STRING !== $elem->type) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #%d ($encodings) must be of type array|string|null, %s given',
                    $function,
                    $argIndex + 1,
                    self::typeLabel($elem)
                ));
            }
            $canonical = MbstringEncodingRegistry::resolve($elem->toString());
            if (null === $canonical) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #%d ($encodings) contains invalid encoding "%s"',
                    $function,
                    $argIndex + 1,
                    $elem->toString()
                ));
            }
            $order[] = $canonical;
        }
        MbstringEncodingRegistry::assertNonEmptyOrder($function, $argIndex, $order);

        return $order;
    }

    private static function stringMatchesEncoding(string $string, string $encoding, bool $strict): bool
    {
        $canonical = MbstringEncodingRegistry::resolve($encoding) ?? $encoding;
        if ('UTF-8' === $canonical) {
            return VmString::isValidUtf8($string);
        }
        if ('ASCII' === $canonical) {
            return self::isAsciiByteString($string);
        }
        if ('ISO-8859-1' === $canonical || '8BIT' === $canonical) {
            if (!$strict) {
                return true;
            }

            return self::strictLatin1RoundTrip($string);
        }
        if (null !== CharsetEngine::parseEncodingSpec($canonical)) {
            return self::checkEncoding($string, $canonical);
        }

        return false;
    }

    private static function isAsciiByteString(string $string): bool
    {
        $len = \strlen($string);
        for ($i = 0; $i < $len; ++$i) {
            if (\ord($string[$i]) >= 0x80) {
                return false;
            }
        }

        return true;
    }

    private static function strictLatin1RoundTrip(string $string): bool
    {
        $utf8 = CharsetEngine::convert('ISO-8859-1', 'UTF-8', $string);
        if (false === $utf8) {
            return false;
        }
        $back = CharsetEngine::convert('UTF-8', 'ISO-8859-1', $utf8);

        return false !== $back && $back === $string;
    }

    /**
     * mb_convert_encoding() core — charset + HTML-ENTITIES / BASE64 / Quoted-Printable
     * (#11212, #22631, #28980, #28982).
     *
     * php-src / libmbfl HTML-ENTITIES is not htmlentities(): ASCII (incl. <>&) stays literal;
     * named HTML entities for mapped non-ASCII; numeric &#N; for everything else (e.g. あ → &#12354;).
     *
     * BASE64 (libmbfl transfer encoding): to-BASE64 is base64_encode($source) of the raw input
     * bytes (from-charset is not re-encoded); from-BASE64 is base64_decode with illegal-byte
     * substitution and returns the decoded byte string without a further charset pass. PHP 8.2+
     * deprecates resolving BASE64 as $to_encoding (php_mb_get_encoding in mbstring.c).
     *
     * Quoted-Printable (#28982): to-QP is libmbfl mb_wchar_to_qprint of raw input bytes
     * (from-charset not re-encoded); from-QP is quoted_printable_decode → raw bytes. Soft wrap
     * at 72 (not quot_print.c's 75). PHP 8.2+ deprecates resolving QP as $to_encoding.
     *
     * Illegal bytes honor MBSTRG(filter_illegal_*) even when $from === $to (#25207).
     */
    public static function convertEncoding(
        string $source,
        string $to,
        string $from,
        ?Frame $frame = null,
        string $function = 'mb_convert_encoding'
    ): string|false {
        $toB64 = self::isBase64Encoding($to);
        $fromB64 = self::isBase64Encoding($from);
        if ($fromB64) {
            if ($toB64) {
                self::deprecateBase64ViaMbstring($frame, $function);

                return \base64_encode($source);
            }

            return self::decodeBase64Pseudo($source);
        }
        if ($toB64) {
            self::deprecateBase64ViaMbstring($frame, $function);

            return \base64_encode($source);
        }

        $toQp = self::isQuotedPrintableEncoding($to);
        $fromQp = self::isQuotedPrintableEncoding($from);
        if ($fromQp) {
            if ($toQp) {
                self::deprecateQuotedPrintableViaMbstring($frame, $function);

                return self::encodeQuotedPrintablePseudo($source);
            }

            return self::decodeQuotedPrintablePseudo($source);
        }
        if ($toQp) {
            self::deprecateQuotedPrintableViaMbstring($frame, $function);

            return self::encodeQuotedPrintablePseudo($source);
        }

        $toHtml = self::isHtmlEntitiesEncoding($to);
        $fromHtml = self::isHtmlEntitiesEncoding($from);
        if ($fromHtml) {
            $utf8 = VmString::html_entity_decode($source, ENT_COMPAT | ENT_HTML401, 'UTF-8');
            if ($toHtml) {
                return $utf8;
            }

            return self::convertBytesWithIllegalSubst('UTF-8', $to, $utf8);
        }
        if ($toHtml) {
            self::deprecateHtmlEntitiesViaMbstring($frame, $function);
            $utf8 = self::convertBytesWithIllegalSubst($from, 'UTF-8', $source);
            if (false === $utf8) {
                return false;
            }

            return self::encodeToHtmlEntities($utf8);
        }

        return self::convertBytesWithIllegalSubst($from, $to, $source);
    }

    /**
     * PHP 8.2+ E_DEPRECATED when a libmbfl transfer encoding is resolved via php_mb_get_encoding.
     *
     * php-src: ext/mbstring/mbstring.c php_mb_get_encoding — from-encoding lists do not deprecate.
     */
    public static function deprecateSpecialTransferEncodingViaMbstring(
        string $canonical,
        ?Frame $frame,
        string $function
    ): void {
        if (!MbstringEncodingRegistry::isSpecialTransferEncoding($canonical)) {
            return;
        }
        if (version_compare(CompilerVersion::languageProfileVersion(), '8.2.0', '<')) {
            return;
        }
        $vm = VM::running();
        if (null === $vm) {
            return;
        }
        if (null === $frame) {
            $frame = $vm->builtinHandlerFrame();
            if (null === $frame) {
                $frames = $vm->context->runStackFrames();
                $frame = [] !== $frames ? $frames[0] : null;
            }
        }
        $message = match ($canonical) {
            'BASE64' => sprintf(
                '%s(): Handling Base64 via mbstring is deprecated; use base64_encode/base64_decode instead',
                $function
            ),
            'UUENCODE' => sprintf(
                '%s(): Handling Uuencode via mbstring is deprecated; use convert_uuencode/convert_uudecode instead',
                $function
            ),
            'Quoted-Printable' => sprintf(
                '%s(): Handling QPrint via mbstring is deprecated; use quoted_printable_encode/quoted_printable_decode instead',
                $function
            ),
            'HTML-ENTITIES' => sprintf(
                '%s(): Handling HTML entities via mbstring is deprecated; use htmlspecialchars, htmlentities, or mb_encode_numericentity/mb_decode_numericentity instead',
                $function
            ),
            default => null,
        };
        if (null === $message) {
            return;
        }
        $vm->context->errors->internalDeprecated($message, $vm->context, $frame);
    }

    /**
     * PHP 8.2+ E_DEPRECATED when BASE64 is resolved via php_mb_get_encoding ($to_encoding).
     *
     * php-src: ext/mbstring/mbstring.c php_mb_get_encoding — from-encoding lists do not deprecate.
     */
    public static function deprecateBase64ViaMbstring(?Frame $frame, string $function = 'mb_convert_encoding'): void
    {
        self::deprecateSpecialTransferEncodingViaMbstring('BASE64', $frame, $function);
    }

    /** PHP 8.2+ E_DEPRECATED for HTML-ENTITIES / HTML / html via php_mb_get_encoding (#28983). */
    public static function deprecateHtmlEntitiesViaMbstring(?Frame $frame, string $function = 'mb_convert_encoding'): void
    {
        self::deprecateSpecialTransferEncodingViaMbstring('HTML-ENTITIES', $frame, $function);
    }

    /** PHP 8.2+ E_DEPRECATED for Quoted-Printable / qprint via php_mb_get_encoding (#28982). */
    public static function deprecateQuotedPrintableViaMbstring(?Frame $frame, string $function = 'mb_convert_encoding'): void
    {
        self::deprecateSpecialTransferEncodingViaMbstring('Quoted-Printable', $frame, $function);
    }

    /**
     * libmbfl Base64 input filter — soft alphabet decode with illegal-char substitution (#28980).
     *
     * Valid alphabet/whitespace/= uses PHP base64_decode; illegal bytes emit
     * mb_substitute_character() into the output and reset the quartet state (Zend 8.2).
     */
    public static function decodeBase64Pseudo(string $source): string
    {
        if (1 === \preg_match('/^[A-Za-z0-9+\/\s=]*$/', $source)) {
            $decoded = \base64_decode($source, false);

            return false === $decoded ? '' : $decoded;
        }

        $out = '';
        $buf = 0;
        $bits = 0;
        $illegal = 0;
        $len = \strlen($source);
        for ($i = 0; $i < $len; ++$i) {
            $c = $source[$i];
            $o = \ord($c);
            if (0x20 === $o || 0x09 === $o || 0x0A === $o || 0x0D === $o) {
                continue;
            }
            if ('=' === $c) {
                break;
            }
            $v = self::base64AlphabetValue($c);
            if ($v < 0) {
                ++$illegal;
                // libmbfl: illegal input emits subst into the byte stream but does not
                // clear the pending sextet buffer (Q!Q== → "?A", not "?").
                $out .= MbstringState::substitutionOutput('8BIT', null);
                continue;
            }
            $buf = ($buf << 6) | $v;
            $bits += 6;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= \chr(($buf >> $bits) & 0xFF);
                $buf &= (1 << $bits) - 1;
            }
        }
        MbstringState::addIllegalChars($illegal);

        return $out;
    }

    private static function base64AlphabetValue(string $c): int
    {
        if ($c >= 'A' && $c <= 'Z') {
            return \ord($c) - 65;
        }
        if ($c >= 'a' && $c <= 'z') {
            return \ord($c) - 71;
        }
        if ($c >= '0' && $c <= '9') {
            return \ord($c) + 4;
        }
        if ('+' === $c) {
            return 62;
        }
        if ('/' === $c) {
            return 63;
        }

        return -1;
    }

    /**
     * libmbfl Quoted-Printable output — mb_wchar_to_qprint (php-src mbfilter_qprint.c; #28982).
     *
     * Soft wrap at 72 (not quot_print.c QPRINT_MAXL=75). Raw bytes: NUL emitted + line reset;
     * LF → CRLF; lone CR dropped; '=' and bytes ≥ 0x80 as =XX.
     */
    public static function encodeQuotedPrintablePseudo(string $source): string
    {
        $out = '';
        $charsOutput = 0;
        $len = \strlen($source);
        for ($i = 0; $i < $len; ++$i) {
            $w = \ord($source[$i]);
            if (0 === $w) {
                $out .= "\0";
                $charsOutput = 0;

                continue;
            }
            if (0x0A === $w) {
                $out .= "\r\n";
                $charsOutput = 0;

                continue;
            }
            if (0x0D === $w) {
                continue;
            }
            if ($charsOutput >= 72) {
                $out .= "=\r\n";
                $charsOutput = 0;
            }
            if ($w >= 0x80 || 0x3D === $w) {
                $out .= sprintf('=%02X', $w);
                $charsOutput += 3;
            } else {
                $out .= $source[$i];
                ++$charsOutput;
            }
        }

        return $out;
    }

    /**
     * libmbfl Quoted-Printable input — equivalent to quoted_printable_decode for Zend 8.2 (#28982).
     */
    public static function decodeQuotedPrintablePseudo(string $source): string
    {
        return VmString::quoted_printable_decode($source);
    }

    /**
     * Charset convert with libmbfl illegal-byte / unconvertible substitution (#25207).
     *
     * php-src: php_mb_convert_encoding → mbfl_buffer_converter with filter_illegal_mode/substchar.
     * Same-charset is not a no-op: illegal sequences are still substituted and counted.
     */
    private static function convertBytesWithIllegalSubst(
        string $fromEncoding,
        string $toEncoding,
        string $source
    ): string|false {
        $fromCanon = CharsetEngine::canonicalize($fromEncoding);
        $toCanon = CharsetEngine::canonicalize($toEncoding);
        if (null === $fromCanon || null === $toCanon) {
            // Encodings CharsetEngine does not know (SJIS, …) — keep prior false-y behavior.
            $fallback = CharsetEngine::convert($fromEncoding, $toEncoding, $source);

            return $fallback;
        }

        if ($fromCanon === $toCanon) {
            return self::scrubInEncoding($source, $fromCanon, true);
        }

        $decoded = self::decodeToCodepointsWithIllegal($source, $fromCanon);
        if (null === $decoded) {
            return CharsetEngine::convert($fromEncoding, $toEncoding, $source);
        }

        return self::encodeCodepointsWithIllegal($decoded['codepoints'], $toCanon, $decoded['illegal']);
    }

    /**
     * @return array{codepoints: list<int|null>, illegal: int}|null null = encoding not handled here
     */
    private static function decodeToCodepointsWithIllegal(string $source, string $canon): ?array
    {
        if ('UTF-8' === $canon) {
            return self::decodeUtf8ToCodepointsWithIllegal($source);
        }
        if ('ASCII' === $canon) {
            return self::decodeAsciiToCodepointsWithIllegal($source);
        }
        if ('ISO-8859-1' === $canon) {
            $cps = [];
            $len = \strlen($source);
            for ($i = 0; $i < $len; ++$i) {
                $cps[] = \ord($source[$i]);
            }

            return ['codepoints' => $cps, 'illegal' => 0];
        }
        if ('8BIT' === $canon || 'BINARY' === $canon) {
            $cps = [];
            $len = \strlen($source);
            for ($i = 0; $i < $len; ++$i) {
                $cps[] = \ord($source[$i]);
            }

            return ['codepoints' => $cps, 'illegal' => 0];
        }

        return null;
    }

    /**
     * @return array{codepoints: list<int|null>, illegal: int}
     */
    private static function decodeUtf8ToCodepointsWithIllegal(string $source): array
    {
        $cps = [];
        $illegal = 0;
        $len = \strlen($source);
        for ($i = 0; $i < $len; ) {
            $need = 0;
            if (!self::utf8SequenceValidAt($source, $len, $i, $need)) {
                $cps[] = null; // MBFL_BAD_INPUT
                ++$illegal;
                ++$i;
                continue;
            }
            if (0 === $need) {
                $cps[] = \ord($source[$i]);
                ++$i;
                continue;
            }
            $byte = \ord($source[$i]);
            $cp = $byte & (0xFF >> (2 + $need));
            for ($j = 1; $j <= $need; ++$j) {
                $cp = ($cp << 6) | (\ord($source[$i + $j]) & 0x3F);
            }
            $cps[] = $cp;
            $i += $need + 1;
        }

        return ['codepoints' => $cps, 'illegal' => $illegal];
    }

    /**
     * @return array{codepoints: list<int|null>, illegal: int}
     */
    private static function decodeAsciiToCodepointsWithIllegal(string $source): array
    {
        $cps = [];
        $illegal = 0;
        $len = \strlen($source);
        for ($i = 0; $i < $len; ++$i) {
            $b = \ord($source[$i]);
            if ($b > 0x7F) {
                $cps[] = null;
                ++$illegal;
            } else {
                $cps[] = $b;
            }
        }

        return ['codepoints' => $cps, 'illegal' => $illegal];
    }

    /**
     * @param list<int|null> $codepoints null entries are MBFL_BAD_INPUT
     */
    private static function encodeCodepointsWithIllegal(array $codepoints, string $toCanon, int $illegalFromDecode): string
    {
        $out = '';
        $illegal = $illegalFromDecode;
        foreach ($codepoints as $cp) {
            if (null === $cp) {
                $out .= MbstringState::substitutionOutput($toCanon, null);
                continue;
            }
            $encoded = self::tryEncodeScalarInto($cp, $toCanon);
            if (null !== $encoded) {
                $out .= $encoded;
                continue;
            }
            // Unconvertible Unicode scalar (libmbfl illegal_output with real codepoint).
            $out .= MbstringState::substitutionOutput($toCanon, $cp);
            ++$illegal;
        }
        MbstringState::addIllegalChars($illegal);

        return $out;
    }

    private static function tryEncodeScalarInto(int $cp, string $canon): ?string
    {
        if ('UTF-8' === $canon) {
            return self::unicodeCodepointToUtf8($cp);
        }
        if ('ASCII' === $canon) {
            return $cp <= 0x7F ? \chr($cp) : null;
        }
        if ('ISO-8859-1' === $canon) {
            return $cp <= 0xFF ? \chr($cp) : null;
        }
        if ('8BIT' === $canon || 'BINARY' === $canon) {
            return $cp <= 0xFF ? \chr($cp) : null;
        }
        // libmbfl utf16be/utf16le filters — was missing, so ASCII fell through to '?' (#28525).
        if ('UTF-16BE' === $canon || 'UTF-16LE' === $canon) {
            return self::unicodeCodepointToUtf16($cp, 'UTF-16BE' === $canon);
        }

        return null;
    }

    private static function unicodeCodepointToUtf8(int $cp): string
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

    /**
     * Encode one Unicode scalar as UTF-16BE or UTF-16LE (php-src libmbfl utf16* filters).
     *
     * Surrogate halves are not scalar values — return null so mb_substitute_character applies.
     */
    private static function unicodeCodepointToUtf16(int $cp, bool $be): ?string
    {
        if ($cp < 0 || $cp > 0x10FFFF) {
            return null;
        }
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            return null;
        }
        if ($cp < 0x10000) {
            return $be
                ? \chr(($cp >> 8) & 0xFF).\chr($cp & 0xFF)
                : \chr($cp & 0xFF).\chr(($cp >> 8) & 0xFF);
        }
        $cp -= 0x10000;
        $hi = 0xD800 | (($cp >> 10) & 0x3FF);
        $lo = 0xDC00 | ($cp & 0x3FF);

        return $be
            ? \chr(($hi >> 8) & 0xFF).\chr($hi & 0xFF)
                .\chr(($lo >> 8) & 0xFF).\chr($lo & 0xFF)
            : \chr($hi & 0xFF).\chr(($hi >> 8) & 0xFF)
                .\chr($lo & 0xFF).\chr(($lo >> 8) & 0xFF);
    }

    /**
     * Same-charset scrub with optional illegal_chars accounting (mb_scrub / mb_convert_encoding).
     */
    private static function scrubInEncoding(string $value, string $canon, bool $recordIllegal): string
    {
        if ('UTF-8' === $canon) {
            return self::scrubUtf8($value, $recordIllegal);
        }
        if ('ASCII' === $canon) {
            return self::scrubAscii($value, $recordIllegal);
        }
        // ISO-8859-1 / 8BIT: every byte is well-formed.
        return $value;
    }

    /**
     * mb_convert_encoding() with array|comma-list $from_encoding (#23562).
     *
     * php-src php_mb_convert_encoding(): single candidate converts directly; multiple candidates
     * run mb_guess_encoding (same algorithm as mb_detect_encoding) then convert. Detect failure
     * emits E_WARNING "Unable to detect character encoding" and returns false.
     *
     * @param list<string> $fromList non-empty resolved encoding names
     */
    public static function convertEncodingWithFromList(
        string $source,
        string $to,
        array $fromList,
        ?Frame $frame = null
    ): string|false {
        if ([] === $fromList) {
            throw new \ValueError(
                'mb_convert_encoding(): Argument #3 ($from_encoding) must specify at least one encoding'
            );
        }
        if (1 === \count($fromList)) {
            return self::convertEncoding($source, $to, $fromList[0], $frame);
        }
        // MBSTRG(strict_detection) is Off in this build (MbstringState::getInfo).
        $detected = self::detectEncoding($source, $fromList, false);
        if (false === $detected) {
            if (null !== $frame?->vmContext) {
                $frame->vmContext->errors->triggerError(
                    'mb_convert_encoding(): Unable to detect character encoding',
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }

            return false;
        }

        return self::convertEncoding($source, $to, $detected, $frame);
    }

    /**
     * Parse mb_convert_encoding() $from_encoding (array|string) into a non-empty list (#23562).
     *
     * php-src: php_mb_parse_encoding_array / php_mb_parse_encoding_list (arg_num 3 → $from_encoding).
     *
     * @return list<string>
     */
    public static function coerceMbConvertFromEncodingList(
        Variable $var,
        string $function = 'mb_convert_encoding',
        int $argIndex = 2
    ): array {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $var->type) {
            return self::parseMbConvertFromEncodingString($var->toString(), $function, $argIndex);
        }
        if (Variable::TYPE_ARRAY !== $var->type) {
            if (EnumCaseSupport::isEnumCaseVariable($var)) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #%d ($from_encoding) must be of type array|string|null, %s given',
                    $function,
                    $argIndex + 1,
                    EnumCaseSupport::typeNameForVariable($var)
                ));
            }
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($from_encoding) must be of type array|string|null, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        $list = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
            $elem = $elem->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($elem)) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #%d ($from_encoding) must be of type array|string|null, %s given',
                    $function,
                    $argIndex + 1,
                    EnumCaseSupport::typeNameForVariable($elem)
                ));
            }
            if (Variable::TYPE_STRING !== $elem->type) {
                // zend zval_try_get_tmp_string — non-string array elems are coerced when possible;
                // objects without __toString and enums TypeError at the array|string boundary.
                if (
                    Variable::TYPE_NULL === $elem->type
                    || Variable::TYPE_BOOLEAN === $elem->type
                    || Variable::TYPE_INTEGER === $elem->type
                    || Variable::TYPE_FLOAT === $elem->type
                ) {
                    $name = $elem->toString();
                } else {
                    throw new \TypeError(sprintf(
                        '%s(): Argument #%d ($from_encoding) must be of type array|string|null, %s given',
                        $function,
                        $argIndex + 1,
                        self::typeLabel($elem)
                    ));
                }
            } else {
                $name = $elem->toString();
            }
            $list[] = self::assertMbConvertFromEncodingName($name, $function, $argIndex);
        }
        if ([] === $list) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($from_encoding) must specify at least one encoding',
                $function,
                $argIndex + 1
            ));
        }

        return $list;
    }

    /**
     * @return list<string>
     */
    private static function parseMbConvertFromEncodingString(
        string $list,
        string $function,
        int $argIndex
    ): array {
        $parts = preg_split('/\s*,\s*/', $list) ?: [];
        $order = [];
        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }
            $order[] = self::assertMbConvertFromEncodingName($part, $function, $argIndex);
        }
        if ([] === $order) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($from_encoding) must specify at least one encoding',
                $function,
                $argIndex + 1
            ));
        }

        return $order;
    }

    private static function assertMbConvertFromEncodingName(
        string $name,
        string $function,
        int $argIndex
    ): string {
        if (self::isHtmlEntitiesEncoding($name)) {
            return 'HTML-ENTITIES';
        }
        if (self::isBase64Encoding($name)) {
            return 'BASE64';
        }
        if (self::isQuotedPrintableEncoding($name)) {
            return 'Quoted-Printable';
        }
        // UUENCODE is valid for lists/aliases (#28983); convert from-list still rejects until #28981.
        $canonical = MbstringEncodingRegistry::resolve($name);
        if (null !== $canonical && null !== CharsetEngine::parseEncodingSpec($canonical)) {
            return $canonical;
        }
        // Fall back to CharsetEngine aliases not in the mbstring registry.
        if (null === $canonical && null !== CharsetEngine::parseEncodingSpec($name)) {
            return $name;
        }
        throw new \ValueError(sprintf(
            '%s(): Argument #%d ($from_encoding) contains invalid encoding "%s"',
            $function,
            $argIndex + 1,
            $name
        ));
    }

    /**
     * libmbfl HTML-ENTITIES output for a UTF-8 string (php-src ext/mbstring; #22631).
     */
    public static function encodeToHtmlEntities(string $utf8): string
    {
        /** @var array<string, string> $named */
        static $named = null;
        if (null === $named) {
            $named = HtmlEntityTable::entitiesEntQuotes();
        }

        $out = '';
        $len = VmString::byteLength($utf8);
        for ($i = 0; $i < $len; ) {
            $width = VmString::utf8CharByteWidth($utf8, $i);
            $char = VmString::byteSlice($utf8, $i, $width);
            $i += $width;
            if (1 === $width && \ord($char) < 0x80) {
                $out .= $char;
                continue;
            }
            if (isset($named[$char])) {
                $out .= $named[$char];
                continue;
            }
            $out .= '&#'.self::utf8CharToCodepoint($char).';';
        }

        return $out;
    }

    /**
     * mb_convert_encoding() array operand — convert string elements, preserve other types (#3222).
     *
     * @param list<string> $fromList
     */
    public static function convertEncodingSourceArray(
        HashTable $table,
        string $to,
        array $fromList,
        ?Frame $frame = null
    ): HashTable|false {
        $out = new HashTable();
        foreach ($table->iterateKeyed(true) as [$key, $value]) {
            $value = $value->resolveIndirect();
            $elem = new Variable();
            if (Variable::TYPE_STRING === $value->type) {
                $converted = self::convertEncodingWithFromList($value->toString(), $to, $fromList, $frame);
                if (false === $converted) {
                    return false;
                }
                $elem->string($converted);
            } else {
                $elem->copyFrom($value);
            }
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $elem);
            } else {
                $out->add($key->toString(), $elem);
            }
        }

        return $out;
    }

    public static function convertCase(
        string $source,
        int $mode,
        string $encoding = 'UTF-8',
        string $function = 'mb_convert_case',
        int $encodingArgIndex = 2
    ): string {
        $encoding = MbstringEncodingRegistry::assertValid($encoding, $function, $encodingArgIndex);
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                $function.'() requires mbstring for encoding '.$encoding.' in this compiler build'
            );
        }

        $utf8 = 'UTF-8' === $encoding;

        return match ($mode) {
            MbstringConstants::MB_CASE_UPPER => $utf8
                ? self::utf8Upper($source)
                : self::asciiUpper($source),
            MbstringConstants::MB_CASE_LOWER => $utf8
                ? self::utf8Lower($source)
                : self::asciiLower($source),
            MbstringConstants::MB_CASE_TITLE => $utf8
                ? self::utf8Title($source)
                : self::asciiTitle($source),
            MbstringConstants::MB_CASE_FOLD => $utf8
                ? self::utf8Fold($source)
                : self::asciiLower($source),
            MbstringConstants::MB_CASE_UPPER_SIMPLE => $utf8
                ? self::utf8UpperSimple($source)
                : self::asciiUpper($source),
            MbstringConstants::MB_CASE_LOWER_SIMPLE => $utf8
                ? self::utf8LowerSimple($source)
                : self::asciiLower($source),
            MbstringConstants::MB_CASE_TITLE_SIMPLE => $utf8
                ? self::utf8TitleSimple($source)
                : self::asciiTitle($source),
            MbstringConstants::MB_CASE_FOLD_SIMPLE => $utf8
                ? self::utf8FoldSimple($source)
                : self::asciiLower($source),
            default => throw new \ValueError('mb_convert_case(): Argument #2 ($mode) must be one of the MB_CASE_* constants'),
        };
    }

    /**
     * UTF-8 case mapping with libmbfl illegal-byte substitution (php_unicode_convert_case; #28629).
     *
     * Invalid sequences become MBFL_BAD_INPUT markers (null here), pass through without case change,
     * then encode via mb_substitute_character — same policy as mb_scrub / mb_convert_encoding.
     *
     * @return list<int|null>
     */
    private static function utf8CodepointsForCaseMap(string $source): array
    {
        return self::decodeUtf8ToCodepointsWithIllegal($source)['codepoints'];
    }

    private static function emitUtf8IllegalSubst(): string
    {
        return MbstringState::substitutionOutput('UTF-8', null);
    }

    private static function utf8Upper(string $source): string
    {
        $out = '';
        foreach (self::utf8CodepointsForCaseMap($source) as $cp) {
            if (null === $cp) {
                $out .= self::emitUtf8IllegalSubst();
                continue;
            }
            foreach (Utf8CaseMap::toUpperCodepoints($cp) as $upperCp) {
                $out .= self::encodeUtf8Codepoint($upperCp);
            }
        }

        return $out;
    }

    private static function utf8Lower(string $source): string
    {
        $out = '';
        foreach (self::utf8CodepointsForCaseMap($source) as $cp) {
            if (null === $cp) {
                $out .= self::emitUtf8IllegalSubst();
                continue;
            }
            foreach (Utf8CaseMap::toLowerCodepoints($cp) as $lowerCp) {
                $out .= self::encodeUtf8Codepoint($lowerCp);
            }
        }

        return $out;
    }

    private static function utf8Fold(string $source): string
    {
        $out = '';
        foreach (self::utf8CodepointsForCaseMap($source) as $cp) {
            if (null === $cp) {
                $out .= self::emitUtf8IllegalSubst();
                continue;
            }
            foreach (Utf8CaseMap::toFoldCodepoints($cp) as $foldCp) {
                $out .= self::encodeUtf8Codepoint($foldCp);
            }
        }

        return $out;
    }

    private static function utf8UpperSimple(string $source): string
    {
        $out = '';
        foreach (self::utf8CodepointsForCaseMap($source) as $cp) {
            if (null === $cp) {
                $out .= self::emitUtf8IllegalSubst();
                continue;
            }
            $out .= self::encodeUtf8Codepoint(Utf8CaseMap::toUpperSimple($cp));
        }

        return $out;
    }

    private static function utf8LowerSimple(string $source): string
    {
        $out = '';
        foreach (self::utf8CodepointsForCaseMap($source) as $cp) {
            if (null === $cp) {
                $out .= self::emitUtf8IllegalSubst();
                continue;
            }
            $out .= self::encodeUtf8Codepoint(Utf8CaseMap::toLowerSimple($cp));
        }

        return $out;
    }

    private static function utf8FoldSimple(string $source): string
    {
        $out = '';
        foreach (self::utf8CodepointsForCaseMap($source) as $cp) {
            if (null === $cp) {
                $out .= self::emitUtf8IllegalSubst();
                continue;
            }
            $out .= self::encodeUtf8Codepoint(Utf8CaseMap::toFoldSimple($cp));
        }

        return $out;
    }

    private static function utf8TitleSimple(string $source): string
    {
        $out = '';
        $upperNext = true;
        foreach (self::utf8CodepointsForCaseMap($source) as $cp) {
            if (null === $cp) {
                // php_unicode_convert_case: BAD_INPUT skips title_mode updates.
                $out .= self::emitUtf8IllegalSubst();
                continue;
            }
            if ($upperNext) {
                // Digraph TITLE forms are 1:1 (Ǆ→ǅ); otherwise simple upper.
                $titleCps = Utf8CaseMap::toTitleCodepoints($cp);
                $out .= self::encodeUtf8Codepoint(
                    1 === \count($titleCps) ? $titleCps[0] : Utf8CaseMap::toUpperSimple($cp)
                );
                $upperNext = false;
            } else {
                $out .= self::encodeUtf8Codepoint(Utf8CaseMap::toLowerSimple($cp));
            }
            if (Utf8CaseMap::isTitleDelimiter($cp)) {
                $upperNext = true;
            }
        }

        return $out;
    }

    private static function utf8Title(string $source): string
    {
        $out = '';
        $upperNext = true;
        foreach (self::utf8CodepointsForCaseMap($source) as $cp) {
            if (null === $cp) {
                $out .= self::emitUtf8IllegalSubst();
                continue;
            }
            if ($upperNext) {
                // SpecialCasing TITLE when distinct from UPPER (Ǆ→ǅ); else upper + lower tail (ß→Ss).
                $titleCps = Utf8CaseMap::toTitleCodepoints($cp);
                $out .= self::encodeUtf8Codepoint($titleCps[0]);
                for ($ui = 1, $un = \count($titleCps); $ui < $un; ++$ui) {
                    $out .= self::encodeUtf8Codepoint(Utf8CaseMap::toLower($titleCps[$ui]));
                }
                $upperNext = false;
            } else {
                foreach (Utf8CaseMap::toLowerCodepoints($cp) as $lowerCp) {
                    $out .= self::encodeUtf8Codepoint($lowerCp);
                }
            }
            if (Utf8CaseMap::isTitleDelimiter($cp)) {
                $upperNext = true;
            }
        }

        return $out;
    }

    /**
     * mb_get_substr / mbfl character units for UTF-8: valid sequences as-is; each illegal byte
     * becomes the current substitute character (php-src ext/mbstring; #28629).
     *
     * @return list<string>
     */
    private static function utf8MbflCharUnits(string $string): array
    {
        $units = [];
        $len = \strlen($string);
        for ($i = 0; $i < $len; ) {
            $need = 0;
            if (!self::utf8SequenceValidAt($string, $len, $i, $need)) {
                $units[] = self::emitUtf8IllegalSubst();
                ++$i;
                continue;
            }
            $units[] = \substr($string, $i, $need + 1);
            $i += $need + 1;
        }

        return $units;
    }

    private static function asciiUpper(string $source): string
    {
        return strtr($source, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    private static function asciiLower(string $source): string
    {
        return strtr($source, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    private static function asciiTitle(string $source): string
    {
        return ucwords(self::asciiLower($source));
    }

    public static function coerceOffsetArg(Frame $frame, string $function, int $argIndex): int
    {
        return VmMath::parseIntBuiltinArgForFrame($frame, $argIndex, $function, $argIndex + 1, 'offset');
    }

    public static function coercePartArg(Variable $var, string $function, int $argIndex = 2): bool
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($before_needle) must be of type bool, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($before_needle) must be of type bool, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toBool();
    }

    /**
     * @return int|false
     */
    public static function stripos(string $haystack, string $needle, int $offset = 0, string $encoding = 'UTF-8')
    {
        return self::utf8Strpos($haystack, $needle, $offset, true, $encoding, 'mb_stripos');
    }

    /**
     * @return int|false
     */
    public static function strrpos(string $haystack, string $needle, int $offset = 0, string $encoding = 'UTF-8')
    {
        return self::utf8Strrpos($haystack, $needle, $offset, false, $encoding, 'mb_strrpos');
    }

    /**
     * @return int|false
     */
    public static function strpos(string $haystack, string $needle, int $offset = 0, string $encoding = 'UTF-8')
    {
        return self::utf8Strpos($haystack, $needle, $offset, false, $encoding, 'mb_strpos');
    }

    /**
     * mb_strstr() — find first occurrence and return haystack from that point (php-src ext/mbstring/mbstring.c).
     *
     * @return string|false
     */
    public static function strstr(string $haystack, string $needle, bool $part = false, string $encoding = 'UTF-8')
    {
        return self::strstrFamily($haystack, $needle, $part, $encoding, false, 'mb_strstr');
    }

    /**
     * mb_stristr() — case-insensitive mb_strstr (php-src ext/mbstring/mbstring.c; #20006).
     *
     * @return string|false
     */
    public static function stristr(string $haystack, string $needle, bool $part = false, string $encoding = 'UTF-8')
    {
        return self::strstrFamily($haystack, $needle, $part, $encoding, true, 'mb_stristr');
    }

    /**
     * mb_strrchr() — find last occurrence and return haystack from that point (php-src ext/mbstring/mbstring.c; #20006).
     *
     * @return string|false
     */
    public static function strrchr(string $haystack, string $needle, bool $part = false, string $encoding = 'UTF-8')
    {
        $encoding = self::assertSearchEncoding($encoding, 'mb_strrchr');
        $pos = self::utf8Strrpos($haystack, $needle, 0, false, $encoding, 'mb_strrchr');
        if (false === $pos) {
            return false;
        }
        if ($part) {
            return VmString::utf8CharSubstr($haystack, 0, $pos);
        }

        return VmString::utf8CharSubstr(
            $haystack,
            $pos,
            VmString::utf8CharLength($haystack) - $pos
        );
    }

    /**
     * @return int|false
     */
    public static function strripos(string $haystack, string $needle, int $offset = 0, string $encoding = 'UTF-8')
    {
        return self::utf8Strrpos($haystack, $needle, $offset, true, $encoding, 'mb_strripos');
    }

    /**
     * @return string|false
     */
    private static function strstrFamily(
        string $haystack,
        string $needle,
        bool $part,
        string $encoding,
        bool $caseInsensitive,
        string $function
    ) {
        $encoding = self::assertSearchEncoding($encoding, $function);
        $pos = self::utf8Strpos($haystack, $needle, 0, $caseInsensitive, $encoding, $function);
        if (false === $pos) {
            return false;
        }
        if ($part) {
            return VmString::utf8CharSubstr($haystack, 0, $pos);
        }
        $charLen = VmString::utf8CharLength($haystack);

        return VmString::utf8CharSubstr($haystack, $pos, $charLen - $pos);
    }

    public static function substr(
        string $string,
        int $start,
        ?int $length = null,
        string $encoding = 'UTF-8',
        bool $warnOnClip = false,
        ?\PHPCompiler\Frame $frame = null,
    ): string {
        $encoding = self::assertSubstrCountEncoding($encoding, 'mb_substr', 3);
        if ('UTF-8' === $encoding) {
            // mb_get_substr: illegal bytes are substitute units (#28629) — required for php_mb_ulcfirst.
            $units = self::utf8MbflCharUnits($string);
            $charLen = \count($units);
            if ($start < 0) {
                $start += $charLen;
            }
            if ($start < 0) {
                $start = 0;
            }
            if ($start >= $charLen) {
                return '';
            }
            if (null === $length) {
                $length = $charLen - $start;
            } elseif ($length < 0) {
                $length = $charLen - $start + $length;
                if ($length < 0) {
                    return '';
                }
            }
            if ($length <= 0) {
                return '';
            }
            if ($warnOnClip && $start + $length > $charLen) {
                if (null !== $frame?->vmContext) {
                    $frame->vmContext->errors->triggerError(
                        'mb_substr(): String is truncated',
                        \PHPCompiler\VM\ErrorReporter::E_WARNING,
                        '' !== $frame->scriptPath ? $frame->scriptPath : null,
                        $frame->vmContext,
                        $frame
                    );
                }
            }

            return \implode('', \array_slice($units, $start, $length));
        }
        $charLen = VmString::utf8CharLength($string);
        if ($start < 0) {
            $start += $charLen;
        }
        if ($start < 0) {
            $start = 0;
        }
        // start >= charLen: empty remainder — no truncate warning (#22489, peers byte substr)
        if ($start >= $charLen) {
            return '';
        }
        if (null === $length) {
            $length = $charLen - $start;
        } elseif ($length < 0) {
            $length = $charLen - $start + $length;
            if ($length < 0) {
                return '';
            }
        }
        if ($length <= 0) {
            return '';
        }
        if ($warnOnClip && $start + $length > $charLen) {
            if (null !== $frame?->vmContext) {
                $frame->vmContext->errors->triggerError(
                    'mb_substr(): String is truncated',
                    \PHPCompiler\VM\ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
        }

        return VmString::utf8CharSubstr($string, $start, $length);
    }

    /**
     * mb_strwidth() — terminal display width (php-src ext/mbstring/mbstring.c mb_get_strwidth; #3495).
     */
    public static function strwidth(string $string, string $encoding = 'UTF-8'): int
    {
        $encoding = self::assertSubstrCountEncoding($encoding, 'mb_strwidth', 1);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return VmString::byteLength($string);
        }

        $width = 0;
        $charLen = VmString::utf8CharLength($string);
        for ($i = 0; $i < $charLen; ++$i) {
            $width += EastAsianWidthTable::characterWidth(
                self::decodeUtf8Char(VmString::utf8CharSubstr($string, $i, 1))
            );
        }

        return $width;
    }

    /**
     * mb_strimwidth() — truncate to display width with optional trim marker (php-src mb_trim_string; #3495).
     */
    public static function strimwidth(
        string $string,
        int $from,
        int $width,
        string $trimmarker = '',
        string $encoding = 'UTF-8'
    ): string {
        $encoding = self::assertSubstrCountEncoding($encoding, 'mb_strimwidth', 4);
        if (0 !== $from) {
            $charLen = 'UTF-8' === $encoding
                ? VmString::utf8CharLength($string)
                : VmString::byteLength($string);
            if ($from < 0) {
                $from += $charLen;
            }
            if ($from < 0 || $from > $charLen) {
                throw new \ValueError('mb_strimwidth(): Argument #2 ($start) is out of range');
            }
            $string = self::substr($string, $from, null, $encoding);
        }

        $totalWidth = self::strwidth($string, $encoding);
        if ($width < 0) {
            $width = $totalWidth + $width;
            if ($width < 0) {
                throw new \ValueError('mb_strimwidth(): Argument #3 ($width) is out of range');
            }
        }
        if ($totalWidth <= $width) {
            return $string;
        }

        $markerWidth = '' !== $trimmarker ? self::strwidth($trimmarker, $encoding) : 0;
        if ('' !== $trimmarker && $width <= $markerWidth) {
            return $trimmarker;
        }

        $contentWidth = $width - $markerWidth;
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return self::trimSingleByteToWidth($string, $contentWidth).$trimmarker;
        }

        return self::trimUtf8ToWidth($string, $contentWidth).$trimmarker;
    }

    /**
     * mb_str_pad() — multibyte-aware str_pad (php-src ext/mbstring/mbstring.c; #6081).
     */
    public static function strPad(
        string $input,
        int $padLength,
        string $padString = ' ',
        int $padType = 1,
        string $encoding = 'UTF-8'
    ): string {
        $encoding = self::assertSubstrCountEncoding($encoding, 'mb_str_pad', 4);
        $inputLength = 'UTF-8' === $encoding
            ? VmString::utf8CharLength($input)
            : VmString::byteLength($input);
        if ($padLength < 0 || $padLength <= $inputLength) {
            return $input;
        }
        if ('' === $padString) {
            throw new \ValueError('mb_str_pad(): Argument #3 ($pad_string) must be a non-empty string');
        }
        $padUnitLength = 'UTF-8' === $encoding
            ? VmString::utf8CharLength($padString)
            : VmString::byteLength($padString);
        if (0 === $padUnitLength) {
            throw new \ValueError('mb_str_pad(): Argument #3 ($pad_string) must be a non-empty string');
        }
        if ($padType < 0 || $padType > 2) {
            throw new \ValueError(
                'mb_str_pad(): Argument #4 ($pad_type) must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH'
            );
        }

        $numPadUnits = $padLength - $inputLength;
        if (1 === $padType) {
            $leftPad = 0;
            $rightPad = $numPadUnits;
        } elseif (0 === $padType) {
            $leftPad = $numPadUnits;
            $rightPad = 0;
        } else {
            $leftPad = intdiv($numPadUnits, 2);
            $rightPad = $numPadUnits - $leftPad;
        }

        if ('UTF-8' === $encoding) {
            return self::repeatUtf8PadString($padString, $padUnitLength, $leftPad)
                .$input
                .self::repeatUtf8PadString($padString, $padUnitLength, $rightPad);
        }

        return self::repeatBytePadString($padString, $padUnitLength, $leftPad)
            .$input
            .self::repeatBytePadString($padString, $padUnitLength, $rightPad);
    }

    private static function repeatUtf8PadString(string $padString, int $padCharLength, int $charLength): string
    {
        if ($charLength <= 0) {
            return '';
        }
        $fullCopies = intdiv($charLength, $padCharLength);
        $remainder = $charLength % $padCharLength;
        $result = \str_repeat($padString, $fullCopies);
        if ($remainder > 0) {
            $result .= VmString::utf8CharSubstr($padString, 0, $remainder);
        }

        return $result;
    }

    private static function repeatBytePadString(string $padString, int $padByteLength, int $byteLength): string
    {
        if ($byteLength <= 0) {
            return '';
        }
        $fullCopies = intdiv($byteLength, $padByteLength);
        $remainder = $byteLength % $padByteLength;
        $result = \str_repeat($padString, $fullCopies);
        if ($remainder > 0) {
            $result .= VmString::byteSlice($padString, 0, $remainder);
        }

        return $result;
    }

    /**
     * mb_strcut() — byte-oriented slice aligned to character boundaries (php-src mb_strcut; #4573).
     *
     * $from and $length are measured in bytes (not codepoints, unlike mb_substr).
     */
    public static function strcut(
        string $string,
        int $from,
        ?int $length = null,
        string $encoding = 'UTF-8'
    ): string {
        $encoding = self::assertSubstrCountEncoding($encoding, 'mb_strcut', 3);
        $byteLen = VmString::byteLength($string);
        if (null === $length) {
            $length = $byteLen;
        }
        if ($from < 0) {
            $from = $byteLen + $from;
            if ($from < 0) {
                $from = 0;
            }
        }
        if ($length < 0) {
            $length = ($byteLen - $from) + $length;
            if ($length < 0) {
                $length = 0;
            }
        }
        if ($from > $byteLen || 0 === $length) {
            return '';
        }
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            if ($length > $byteLen - $from) {
                $length = $byteLen - $from;
            }

            return VmString::byteSlice($string, $from, $length);
        }

        return self::utf8ByteSafeCut($string, $from, $length);
    }

    /** UTF-8 byte cut with character-boundary alignment (php-src ext/mbstring/mbstring.c mb_strcut). */
    private static function utf8ByteSafeCut(string $string, int $from, int $length): string
    {
        $byteLen = VmString::byteLength($string);
        $start = self::utf8AlignByteStart($string, $from, $byteLen);
        if ($start >= $byteLen) {
            return '';
        }
        if ($length >= $byteLen - $start) {
            return VmString::byteSlice($string, $start, $byteLen - $start);
        }
        $end = self::utf8AlignByteEnd($string, $start, $start + $length, $byteLen);

        return VmString::byteSlice($string, $start, $end - $start);
    }

    private static function utf8AlignByteStart(string $string, int $from, int $byteLen): int
    {
        $p = 0;
        $lastWidth = 1;
        while ($p < $from && $p < $byteLen) {
            $lastWidth = VmString::utf8CharByteWidth($string, $p);
            $p += $lastWidth;
        }
        if ($p > $from) {
            $p -= $lastWidth;
        }

        return $p;
    }

    private static function utf8AlignByteEnd(
        string $string,
        int $start,
        int $target,
        int $byteLen
    ): int {
        $p = $start;
        $lastWidth = 1;
        while ($p < $target && $p < $byteLen) {
            $lastWidth = VmString::utf8CharByteWidth($string, $p);
            $p += $lastWidth;
        }
        if ($p > $target) {
            $p -= $lastWidth;
        }

        return $p;
    }

    public static function strtolower(string $string, string $encoding = 'UTF-8'): string
    {
        return self::convertCase(
            $string,
            MbstringConstants::MB_CASE_LOWER,
            $encoding,
            'mb_strtolower',
            1
        );
    }

    public static function strtoupper(string $string, string $encoding = 'UTF-8'): string
    {
        return self::convertCase(
            $string,
            MbstringConstants::MB_CASE_UPPER,
            $encoding,
            'mb_strtoupper',
            1
        );
    }

    /** php-src ext/mbstring/mbstring.c php_mb_ulcfirst — first multibyte char only (#17609). */
    public static function ucfirst(string $string, string $encoding = 'UTF-8'): string
    {
        return self::ulcfirst(
            $string,
            MbstringConstants::MB_CASE_TITLE,
            $encoding,
            'mb_ucfirst',
            1
        );
    }

    /** php-src ext/mbstring/mbstring.c php_mb_ulcfirst — first multibyte char only (#17609). */
    public static function lcfirst(string $string, string $encoding = 'UTF-8'): string
    {
        return self::ulcfirst(
            $string,
            MbstringConstants::MB_CASE_LOWER,
            $encoding,
            'mb_lcfirst',
            1
        );
    }

    /** php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_ucwords) — MB_CASE_TITLE per word (#20799). */
    public static function ucwords(string $string, string $encoding = 'UTF-8'): string
    {
        return self::convertCase(
            $string,
            MbstringConstants::MB_CASE_TITLE,
            $encoding,
            'mb_ucwords',
            1
        );
    }

    private static function ulcfirst(
        string $string,
        int $mode,
        string $encoding,
        string $function,
        int $encodingArgIndex
    ): string {
        $encoding = MbstringEncodingRegistry::assertValid($encoding, $function, $encodingArgIndex);
        if ('' === $string) {
            return $string;
        }
        $first = self::substr($string, 0, 1, $encoding);
        $head = self::convertCase($first, $mode, $encoding, $function, $encodingArgIndex);
        if ($first === $head) {
            return $string;
        }
        $rest = self::substr($string, 1, null, $encoding);

        return $head.$rest;
    }

    public static function coerceStartArg(Frame $frame, string $function, int $argIndex): int
    {
        return VmMath::parseIntBuiltinArgForFrame($frame, $argIndex, $function, $argIndex + 1, 'start');
    }

    public static function coerceLengthArg(Frame $frame, string $function, int $argIndex): int
    {
        return VmMath::parseIntBuiltinArgForFrame($frame, $argIndex, $function, $argIndex + 1, 'length');
    }

    public static function coerceOptionalLengthArg(Frame $frame, string $function, int $argIndex): ?int
    {
        $var = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmMath::parseIntBuiltinArgForFrame($frame, $argIndex, $function, $argIndex + 1, 'length');
    }

    /**
     * @return string|false
     */
    public static function strrichr(string $haystack, string $needle, bool $part = false, string $encoding = 'UTF-8')
    {
        $encoding = self::assertSearchEncoding($encoding, 'mb_strrichr');
        $lowerHay = self::convertCase($haystack, MbstringConstants::MB_CASE_LOWER, $encoding);
        $lowerNeedle = self::convertCase($needle, MbstringConstants::MB_CASE_LOWER, $encoding);
        $pos = self::utf8Strrpos($lowerHay, $lowerNeedle, 0, false, $encoding, 'mb_strrichr');
        if (false === $pos) {
            return false;
        }
        if ($part) {
            return VmString::utf8CharSubstr($haystack, 0, $pos);
        }

        return VmString::utf8CharSubstr(
            $haystack,
            $pos,
            VmString::utf8CharLength($haystack) - $pos
        );
    }

    /**
     * @return int|false
     */
    private static function utf8Strpos(
        string $haystack,
        string $needle,
        int $offset,
        bool $caseInsensitive,
        string $encoding,
        string $function
    ) {
        $encoding = self::assertSearchEncoding($encoding, $function);
        if ($caseInsensitive) {
            $haystack = self::convertCase($haystack, MbstringConstants::MB_CASE_LOWER, $encoding);
            $needle = self::convertCase($needle, MbstringConstants::MB_CASE_LOWER, $encoding);
        }
        $hayLen = VmString::utf8CharLength($haystack);
        $needleLen = VmString::utf8CharLength($needle);
        $offset = self::normalizeCharOffset($hayLen, $offset, $function);
        if (0 === $needleLen) {
            return $offset;
        }
        for ($pos = $offset; $pos <= $hayLen - $needleLen; ++$pos) {
            if (VmString::utf8CharSubstr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
        }

        return false;
    }

    /**
     * @return int|false
     */
    private static function utf8Strrpos(
        string $haystack,
        string $needle,
        int $offset,
        bool $caseInsensitive,
        string $encoding,
        string $function
    ) {
        $encoding = self::assertSearchEncoding($encoding, $function);
        if ($caseInsensitive) {
            $haystack = self::convertCase($haystack, MbstringConstants::MB_CASE_LOWER, $encoding);
            $needle = self::convertCase($needle, MbstringConstants::MB_CASE_LOWER, $encoding);
        }
        $hayLen = VmString::utf8CharLength($haystack);
        $needleLen = VmString::utf8CharLength($needle);
        $minStart = 0;
        $maxStart = $hayLen - $needleLen;
        if ($offset < 0) {
            $maxStart = $hayLen + $offset;
            if ($maxStart < 0) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #3 ($offset) must be contained in argument #1 ($haystack)',
                    $function
                ));
            }
            if (0 === $needleLen) {
                return $maxStart;
            }
            $maxStart -= $needleLen;
        } else {
            $minStart = $offset;
        }
        if (0 === $needleLen) {
            return $hayLen;
        }
        if ($minStart > $maxStart) {
            return false;
        }
        for ($pos = $maxStart; $pos >= $minStart; --$pos) {
            if (VmString::utf8CharSubstr($haystack, $pos, $needleLen) === $needle) {
                return $pos;
            }
        }

        return false;
    }

    private static function normalizeCharOffset(int $hayLen, int $offset, string $function): int
    {
        if ($offset < 0) {
            $offset += $hayLen;
        }
        if ($offset < 0 || $offset > $hayLen) {
            throw new \ValueError(sprintf(
                '%s(): Argument #3 ($offset) must be contained in argument #1 ($haystack)',
                $function
            ));
        }

        return $offset;
    }

    /**
     * php-src php_mb_check_encoding / zend_argument_value_error for unknown names (#27945);
     * LogicException only for valid encodings this build does not yet implement.
     */
    private static function assertSearchEncoding(string $encoding, string $function, int $argIndex = 3): string
    {
        return self::assertSubstrCountEncoding($encoding, $function, $argIndex);
    }

    public static function assertSubstrCountEncoding(
        string $encoding,
        string $function = 'mb_substr_count',
        int $argIndex = 2
    ): string {
        $encoding = MbstringEncodingRegistry::assertValid($encoding, $function, $argIndex);
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                $function.'() requires mbstring for encoding '.$encoding.' in this compiler build'
            );
        }

        return $encoding;
    }

    /**
     * @return array<int, mixed>|string|int|null
     */
    public static function coerceCheckEncodingValueArg(
        Variable $var,
        string $function,
        int $argIndex = 0
    ): array|string|int|null {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($value) must be of type array|string|null, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            $out = [];
            foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
                $elem = $elem->resolveIndirect();
                if (Variable::TYPE_OBJECT === $elem->type) {
                    throw new \LogicException(
                        $function.'(): array value contains object; use checkEncodingForVariable()'
                    );
                }
                $out[] = self::checkEncodingScalarToPhp($elem);
            }

            return $out;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($value) must be of type array|string|null, %s given',
                $function,
                $argIndex + 1,
                $var->toObject()->class->name
            ));
        }

        throw new \TypeError(sprintf(
            '%s(): Argument #%d ($value) must be of type array|string|null, %s given',
            $function,
            $argIndex + 1,
            self::typeLabel($var)
        ));
    }

    public static function checkEncodingForVariable(?Variable $valueVar, ?string $encoding = null): bool
    {
        if (null === $valueVar) {
            return self::checkEncoding(null, $encoding);
        }
        $var = $valueVar->resolveIndirect();
        if (Variable::TYPE_ARRAY === $var->type) {
            foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
                if (Variable::TYPE_OBJECT === $elem->resolveIndirect()->type) {
                    return false;
                }
            }
        }

        return self::checkEncoding(
            self::coerceCheckEncodingValueArg($valueVar, 'mb_check_encoding', 0),
            $encoding
        );
    }

    /**
     * @param array<int, mixed>|string|int|null $value
     */
    public static function checkEncoding(array|string|int|null $value = null, ?string $encoding = null): bool
    {
        $encoding = null === $encoding ? 'UTF-8' : $encoding;
        self::assertCheckEncodingName($encoding);

        if (null === $value) {
            return true;
        }
        if (\is_int($value)) {
            $value = (string) $value;
        }
        if (\is_string($value)) {
            return self::isValidInEncoding($value, $encoding);
        }

        foreach ($value as $item) {
            if (\is_object($item)) {
                return false;
            }
            if (\is_int($item)) {
                $item = (string) $item;
            }
            if (!\is_string($item) || !self::isValidInEncoding($item, $encoding)) {
                return false;
            }
        }

        return true;
    }

    public static function assertCheckEncodingName(string $encoding): void
    {
        if (null === CharsetEngine::parseEncodingSpec($encoding)) {
            throw new \ValueError(sprintf(
                'mb_check_encoding(): Argument #2 ($encoding) must be a valid encoding, "%s" given',
                $encoding
            ));
        }
    }

    private static function isValidInEncoding(string $value, string $encoding): bool
    {
        $canonical = CharsetEngine::canonicalize($encoding) ?? $encoding;
        if ('UTF-8' === $canonical) {
            return VmString::isValidUtf8($value);
        }
        if ('ASCII' === $canonical || '8BIT' === $canonical) {
            return true;
        }

        throw new \LogicException(
            'mb_check_encoding() requires mbstring for encoding '.$encoding.' in this compiler build'
        );
    }

    private static function isValidUtf8(string $value): bool
    {
        $len = \strlen($value);
        for ($i = 0; $i < $len; ) {
            $need = 0;
            if (!self::utf8SequenceValidAt($value, $len, $i, $need)) {
                return false;
            }
            $i += $need + 1;
        }

        return true;
    }

    /**
     * mb_scrub() — replace invalid byte sequences (php-src ext/mbstring/mbstring.c; PHP 8.4, #6050).
     *
     * Honors mb_substitute_character() and increments MBSTRG(illegalchars) like php-src.
     */
    public static function scrub(string $value, ?string $encoding = null): string
    {
        $encoding = null === $encoding ? 'UTF-8' : $encoding;
        self::assertScrubEncodingName($encoding);
        $canonical = self::canonicalScrubEncoding($encoding);
        if ('UTF-8' === $canonical) {
            return self::scrubUtf8($value, true);
        }
        if ('ASCII' === $canonical) {
            return self::scrubAscii($value, true);
        }
        if ('8BIT' === $canonical) {
            return $value;
        }

        throw new \LogicException(
            'mb_scrub() requires mbstring for encoding '.$encoding.' in this compiler build'
        );
    }

    public static function assertScrubEncodingName(string $encoding): void
    {
        if (null !== self::canonicalScrubEncoding($encoding)) {
            return;
        }
        throw new \ValueError(\sprintf(
            'mb_scrub(): Argument #2 ($encoding) must be a valid encoding, "%s" given',
            $encoding
        ));
    }

    private static function canonicalScrubEncoding(string $encoding): ?string
    {
        $upper = strtoupper($encoding);
        if ('UTF-8' === $upper || 'UTF8' === $upper) {
            return 'UTF-8';
        }
        if ('ASCII' === $upper) {
            return 'ASCII';
        }
        if ('8BIT' === $upper || 'BINARY' === $upper) {
            return '8BIT';
        }

        return CharsetEngine::canonicalize($encoding);
    }

    private static function scrubAscii(string $value, bool $recordIllegal = false): string
    {
        $out = '';
        $illegal = 0;
        $len = \strlen($value);
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($value[$i]);
            if ($byte < 0x80) {
                $out .= $value[$i];
            } else {
                $out .= MbstringState::substitutionOutput('ASCII', null);
                ++$illegal;
            }
        }
        if ($recordIllegal) {
            MbstringState::addIllegalChars($illegal);
        }

        return $out;
    }

    private static function scrubUtf8(string $value, bool $recordIllegal = false): string
    {
        $out = '';
        $illegal = 0;
        $len = \strlen($value);
        for ($i = 0; $i < $len; ) {
            $byte = \ord($value[$i]);
            if ($byte < 0x80) {
                $out .= $value[$i];
                ++$i;
                continue;
            }
            $need = 0;
            if (!self::utf8SequenceValidAt($value, $len, $i, $need)) {
                $out .= MbstringState::substitutionOutput('UTF-8', null);
                ++$illegal;
                ++$i;
                continue;
            }
            $out .= \substr($value, $i, $need + 1);
            $i += $need + 1;
        }
        if ($recordIllegal) {
            MbstringState::addIllegalChars($illegal);
        }

        return $out;
    }

    /**
     * @param-out int $need continuation byte count when lead byte is multi-byte
     */
    private static function utf8SequenceValidAt(string $value, int $len, int $i, ?int &$need = null): bool
    {
        $byte = \ord($value[$i]);
        if ($byte < 0x80) {
            $need = 0;

            return true;
        }
        if (($byte & 0xE0) === 0xC0) {
            $need = 1;
            $min = 0x80;
        } elseif (($byte & 0xF0) === 0xE0) {
            $need = 2;
            $min = 0x800;
        } elseif (($byte & 0xF8) === 0xF0) {
            $need = 3;
            $min = 0x10000;
        } else {
            $need = 0;

            return false;
        }
        if ($i + $need >= $len) {
            return false;
        }
        $cp = $byte & (0xFF >> (2 + $need));
        for ($j = 1; $j <= $need; ++$j) {
            $next = \ord($value[$i + $j]);
            if (($next & 0xC0) !== 0x80) {
                return false;
            }
            $cp = ($cp << 6) | ($next & 0x3F);
        }
        if ($cp < $min || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            return false;
        }

        return true;
    }

    /**
     * @return string|int|float|bool|null
     */
    private static function checkEncodingScalarToPhp(Variable $var): string|int|float|bool|null
    {
        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_STRING => $var->toString(),
            default => null,
        };
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }

    public static function runTrimBuiltin(Frame $frame, string $function, int $mode): void
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \ArgumentCountError(sprintf(
                '%s() expects at least 1 argument, %d given',
                $function,
                \count($frame->calledArgs)
            ));
        }
        foreach (array_keys($frame->calledArgs) as $idx) {
            if ($idx < 0 || $idx > 2) {
                throw new \ArgumentCountError(sprintf(
                    '%s() expects at most 3 arguments, %d given',
                    $function,
                    \count($frame->calledArgs)
                ));
            }
        }
        // Zend 8.4 ZPP soft-null + DEP (not TypeError) — #24176, reverts #17132.
        $source = VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            $function,
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $what = null;
        if (isset($frame->calledArgs[1])) {
            $whatVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $whatVar->type) {
                $what = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    $function,
                    1,
                    'characters'
                );
            }
        }
        $encoding = isset($frame->calledArgs[2])
            ? self::coerceEncodingArg($frame->calledArgs[2], $function, 2)
            : 'UTF-8';
        $frame->returnVar->string(self::trimString($source, $what, $encoding, $mode, $function));
    }

    public static function trimString(
        string $source,
        ?string $what,
        string $encoding,
        int $mode,
        string $function = 'mb_trim'
    ): string {
        $encoding = self::assertTrimEncoding($encoding, $function);
        if (null === $what) {
            $trimSet = self::defaultTrimSet();
        } elseif ('' === $what) {
            return $source;
        } else {
            $trimSet = self::trimSetFromWhat($what, $encoding);
        }
        if ('UTF-8' === $encoding) {
            return self::trimUtf8($source, $trimSet, $mode);
        }

        return self::trimSingleByte($source, $trimSet, $mode);
    }

    /**
     * @return array<int, true>
     */
    private static function defaultTrimSet(): array
    {
        $set = [];
        foreach (self::DEFAULT_TRIM_CODEPOINTS as $cp) {
            $set[$cp] = true;
        }

        return $set;
    }

    /**
     * @return array<int, true>
     */
    private static function trimSetFromWhat(string $what, string $encoding): array
    {
        $set = [];
        foreach (self::codepointsInString($what, $encoding) as $cp) {
            $set[$cp] = true;
        }

        return $set;
    }

    /**
     * @return list<int>
     */
    private static function codepointsInString(string $string, string $encoding): array
    {
        if ('UTF-8' === $encoding) {
            $out = [];
            $charLen = VmString::utf8CharLength($string);
            for ($i = 0; $i < $charLen; ++$i) {
                $out[] = self::decodeUtf8Char(VmString::utf8CharSubstr($string, $i, 1));
            }

            return $out;
        }
        $out = [];
        $byteLen = \strlen($string);
        for ($i = 0; $i < $byteLen; ++$i) {
            $out[] = \ord($string[$i]);
        }

        return $out;
    }

    /**
     * @param array<int, true> $trimSet
     */
    private static function trimUtf8(string $source, array $trimSet, int $mode): string
    {
        $charLen = VmString::utf8CharLength($source);
        if (0 === $charLen) {
            return '';
        }
        $left = 0;
        $right = 0;
        $currentMode = $mode;
        for ($i = 0; $i < $charLen; ++$i) {
            $cp = self::decodeUtf8Char(VmString::utf8CharSubstr($source, $i, 1));
            if (isset($trimSet[$cp])) {
                if ($currentMode & self::MB_LTRIM) {
                    ++$left;
                }
                if ($currentMode & self::MB_RTRIM) {
                    ++$right;
                }
            } else {
                $currentMode &= ~self::MB_LTRIM;
                if ($currentMode & self::MB_RTRIM) {
                    $right = 0;
                }
            }
        }
        if (0 === $left && 0 === $right) {
            return $source;
        }

        return VmString::utf8CharSubstr($source, $left, $charLen - $left - $right);
    }

    /**
     * @param array<int, true> $trimSet
     */
    private static function trimSingleByte(string $source, array $trimSet, int $mode): string
    {
        $byteLen = \strlen($source);
        if (0 === $byteLen) {
            return '';
        }
        $left = 0;
        $right = 0;
        $currentMode = $mode;
        for ($i = 0; $i < $byteLen; ++$i) {
            $cp = \ord($source[$i]);
            if (isset($trimSet[$cp])) {
                if ($currentMode & self::MB_LTRIM) {
                    ++$left;
                }
                if ($currentMode & self::MB_RTRIM) {
                    ++$right;
                }
            } else {
                $currentMode &= ~self::MB_LTRIM;
                if ($currentMode & self::MB_RTRIM) {
                    $right = 0;
                }
            }
        }
        if (0 === $left && 0 === $right) {
            return $source;
        }

        return \substr($source, $left, $byteLen - $left - $right);
    }

    private static function trimUtf8ToWidth(string $string, int $contentWidth): string
    {
        if ($contentWidth <= 0) {
            return '';
        }
        $used = 0;
        $out = '';
        $charLen = VmString::utf8CharLength($string);
        for ($i = 0; $i < $charLen; ++$i) {
            $char = VmString::utf8CharSubstr($string, $i, 1);
            $charWidth = EastAsianWidthTable::characterWidth(self::decodeUtf8Char($char));
            if ($used + $charWidth > $contentWidth) {
                break;
            }
            $out .= $char;
            $used += $charWidth;
        }

        return $out;
    }

    private static function trimSingleByteToWidth(string $string, int $contentWidth): string
    {
        if ($contentWidth <= 0) {
            return '';
        }
        $byteLen = VmString::byteLength($string);
        if ($contentWidth >= $byteLen) {
            return $string;
        }

        return VmString::byteSlice($string, 0, $contentWidth);
    }

    private static function decodeUtf8Char(string $char): int
    {
        $len = \strlen($char);
        if (0 === $len) {
            return 0;
        }
        $b0 = \ord($char[0]);
        if ($b0 < 0x80) {
            return $b0;
        }
        if ($len >= 2 && ($b0 & 0xE0) === 0xC0) {
            return (($b0 & 0x1F) << 6) | (\ord($char[1]) & 0x3F);
        }
        if ($len >= 3 && ($b0 & 0xF0) === 0xE0) {
            return (($b0 & 0x0F) << 12) | ((\ord($char[1]) & 0x3F) << 6) | (\ord($char[2]) & 0x3F);
        }
        if ($len >= 4 && ($b0 & 0xF8) === 0xF0) {
            return (($b0 & 0x07) << 18) | ((\ord($char[1]) & 0x3F) << 12)
                | ((\ord($char[2]) & 0x3F) << 6) | (\ord($char[3]) & 0x3F);
        }

        return $b0;
    }

    /** UTF-8 single-character decode for mbstring helpers (#13099). */
    public static function utf8CharToCodepoint(string $char): int
    {
        return self::decodeUtf8Char($char);
    }

    /**
     * php-src php_mb_get_encoding / zend_argument_value_error for unknown names (#23883);
     * LogicException only for valid encodings this build does not yet trim.
     */
    private static function assertTrimEncoding(string $encoding, string $function, int $argIndex = 2): string
    {
        $encoding = MbstringEncodingRegistry::assertValid($encoding, $function, $argIndex);
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                $function.'() requires mbstring for encoding '.$encoding.' in this compiler build'
            );
        }

        return $encoding;
    }

    /**
     * @return list<int>
     */
    public static function coerceConvMapArg(Variable $var, string $function): array
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #2 ($map) must be of type array, %s given',
                $function,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #2 ($map) must be of type array, %s given',
                $function,
                self::typeLabel($var)
            ));
        }

        $elems = [];
        foreach ($var->toArray()->iterateKeyed(true) as [, $elem]) {
            $elem = $elem->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $elem->type) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #2 ($map) must only be composed of values of type int',
                    $function
                ));
            }
            $elems[] = $elem->toInt();
        }

        return self::validateConvMapElements($elems, $function);
    }

    /**
     * JIT/AOT helper path — read convmap from packed hashtable (#7237).
     *
     * @return list<int>
     */
    public static function convMapFromHashTable(\PHPCompiler\VM\HashTable $ht, string $function): array
    {
        $elems = [];
        foreach ($ht->iterateKeyed(true) as [, $elem]) {
            $elem = $elem->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $elem->type) {
                throw new \ValueError(sprintf(
                    '%s(): Argument #2 ($map) must only be composed of values of type int',
                    $function
                ));
            }
            $elems[] = $elem->toInt();
        }

        return self::validateConvMapElements($elems, $function);
    }

    public static function validateConvMapElements(array $elems, string $function): array
    {
        if (0 !== (\count($elems) % 4)) {
            throw new \ValueError(sprintf(
                '%s(): Argument #2 ($map) must have a multiple of 4 elements',
                $function
            ));
        }

        return $elems;
    }

    public static function resolveNumericEntityEncoding(
        ?string $encoding,
        string $function,
        int $argIndex = 2
    ): string {
        $encoding = null === $encoding ? 'UTF-8' : $encoding;
        if (null === CharsetEngine::parseEncodingSpec($encoding)) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($encoding) must be a valid encoding, "%s" given',
                $function,
                $argIndex + 1,
                $encoding
            ));
        }

        return $encoding;
    }

    /**
     * @param list<int> $convmap
     */
    public static function encodeNumericEntity(
        string $str,
        array $convmap,
        string $encoding = 'UTF-8',
        bool $isHex = false
    ): string {
        self::assertNumericEntityEncoding($encoding);
        if ('UTF-8' === $encoding) {
            return self::encodeNumericEntityUtf8($str, $convmap, $isHex);
        }

        return self::encodeNumericEntitySingleByte($str, $convmap, $isHex);
    }

    /**
     * @param list<int> $convmap
     */
    public static function decodeNumericEntity(string $str, array $convmap, string $encoding = 'UTF-8'): string
    {
        self::assertNumericEntityEncoding($encoding);
        if ('UTF-8' === $encoding) {
            return self::decodeNumericEntityUtf8($str, $convmap);
        }

        return self::decodeNumericEntitySingleByte($str, $convmap);
    }

    /**
     * @param list<int> $convmap
     */
    private static function numericEntityConvert(int $wchar, array $convmap, int &$entityNum): bool
    {
        $count = \count($convmap);
        for ($i = 0; $i < $count; $i += 4) {
            $loCode = $convmap[$i];
            $hiCode = $convmap[$i + 1];
            $offset = $convmap[$i + 2];
            $mask = $convmap[$i + 3];
            if ($wchar >= $loCode && $wchar <= $hiCode) {
                $entityNum = ($wchar + $offset) & $mask;

                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $convmap
     */
    private static function numericEntityDeconvert(int $number, array $convmap, int &$codepoint): bool
    {
        $count = \count($convmap);
        for ($i = 0; $i < $count; $i += 4) {
            $loCode = $convmap[$i];
            $hiCode = $convmap[$i + 1];
            $offset = $convmap[$i + 2];
            $codepoint = $number - $offset;
            if ($codepoint >= $loCode && $codepoint <= $hiCode) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $convmap
     */
    private static function encodeNumericEntityUtf8(string $str, array $convmap, bool $isHex): string
    {
        $out = '';
        foreach (self::codepointsInString($str, 'UTF-8') as $wchar) {
            $entityNum = 0;
            if (self::numericEntityConvert($wchar, $convmap, $entityNum)) {
                $out .= '&#';
                if ($isHex) {
                    $out .= 'x';
                }
                if (0 === $entityNum) {
                    $out .= '0';
                } elseif ($isHex) {
                    $out .= strtoupper(dechex($entityNum));
                } else {
                    $out .= (string) $entityNum;
                }
                $out .= ';';
            } else {
                $out .= self::encodeUtf8Codepoint($wchar);
            }
        }

        return $out;
    }

    /**
     * @param list<int> $convmap
     */
    private static function encodeNumericEntitySingleByte(string $str, array $convmap, bool $isHex): string
    {
        $out = '';
        $byteLen = \strlen($str);
        for ($i = 0; $i < $byteLen; ++$i) {
            $wchar = \ord($str[$i]);
            $entityNum = 0;
            if (self::numericEntityConvert($wchar, $convmap, $entityNum)) {
                $out .= '&#';
                if ($isHex) {
                    $out .= 'x';
                }
                if (0 === $entityNum) {
                    $out .= '0';
                } elseif ($isHex) {
                    $out .= strtoupper(dechex($entityNum));
                } else {
                    $out .= (string) $entityNum;
                }
                $out .= ';';
            } else {
                $out .= $str[$i];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $convmap
     */
    private static function decodeNumericEntityUtf8(string $str, array $convmap): string
    {
        $len = \strlen($str);
        $out = '';
        $i = 0;
        while ($i < $len) {
            if ('&' !== $str[$i]) {
                $out .= $str[$i];
                ++$i;
                continue;
            }
            $replacement = '';
            $end = $i;
            $consumed = self::tryDecodeNumericEntityAt($str, $i, $convmap, $replacement, $end);
            if ($consumed) {
                $out .= $replacement;
                $i = $end;
                continue;
            }
            $out .= $str[$i];
            ++$i;
        }

        return $out;
    }

    /**
     * @param list<int> $convmap
     */
    private static function decodeNumericEntitySingleByte(string $str, array $convmap): string
    {
        $decoded = self::decodeNumericEntityUtf8($str, $convmap);
        $out = '';
        foreach (self::codepointsInString($decoded, 'UTF-8') as $cp) {
            if ($cp > 0xFF) {
                $out .= '?';
            } else {
                $out .= \chr($cp);
            }
        }

        return $out;
    }

    /**
     * @param list<int> $convmap
     */
    private static function tryDecodeNumericEntityAt(
        string $str,
        int $pos,
        array $convmap,
        ?string &$replacement,
        int &$end
    ): bool {
        $len = \strlen($str);
        if ($pos + 2 >= $len || '#' !== $str[$pos + 1]) {
            return false;
        }

        if ('x' === $str[$pos + 2] || 'X' === $str[$pos + 2]) {
            $digitStart = $pos + 3;
            $digitEnd = $digitStart;
            while ($digitEnd < $len && ctype_xdigit($str[$digitEnd])) {
                ++$digitEnd;
            }
            $entityLen = $digitEnd - $pos;
            $digitLen = $digitEnd - $digitStart;
            if ($digitLen < 1 || $digitLen > 8 || $entityLen < 4 || $entityLen > 11) {
                return false;
            }
            $value = (int) \hexdec(substr($str, $digitStart, $digitLen));
            $codepoint = 0;
            if (!self::numericEntityDeconvert($value, $convmap, $codepoint)) {
                return false;
            }
            $replacement = self::encodeUtf8Codepoint($codepoint);
            $end = $digitEnd;
            if ($end < $len && ';' === $str[$end]) {
                ++$end;
            }

            return true;
        }

        $digitStart = $pos + 2;
        $digitEnd = $digitStart;
        while ($digitEnd < $len && $str[$digitEnd] >= '0' && $str[$digitEnd] <= '9') {
            ++$digitEnd;
        }
        $entityLen = $digitEnd - $pos;
        $digitLen = $digitEnd - $digitStart;
        if ($digitLen < 1 || $digitLen > 10 || $entityLen < 3 || $entityLen > 12) {
            return false;
        }
        $value = 0;
        for ($k = $digitStart; $k < $digitEnd; ++$k) {
            if ($value > 0x19999999) {
                return false;
            }
            $value = ($value * 10) + (\ord($str[$k]) - 48);
        }
        $codepoint = 0;
        if (!self::numericEntityDeconvert($value, $convmap, $codepoint)) {
            return false;
        }
        $replacement = self::encodeUtf8Codepoint($codepoint);
        $end = $digitEnd;
        if ($end < $len && ';' === $str[$end]) {
            ++$end;
        }

        return true;
    }

    public static function encodeUtf8Codepoint(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12))
                .\chr(0x80 | (($cp >> 6) & 0x3F))
                .\chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            .\chr(0x80 | (($cp >> 12) & 0x3F))
            .\chr(0x80 | (($cp >> 6) & 0x3F))
            .\chr(0x80 | ($cp & 0x3F));
    }

    /**
     * mb_chr() — codepoint to character (php-src ext/mbstring/mbstring.c; #4559).
     */
    public static function chr(int $codepoint, string $encoding): string|false
    {
        $encoding = MbstringEncodingRegistry::assertValid($encoding, 'mb_chr', 1);
        if ('UTF-8' === $encoding) {
            if (!self::isValidUnicodeCodepoint($codepoint)) {
                return false;
            }

            return self::encodeUtf8Codepoint($codepoint);
        }
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            if ($codepoint < 0 || $codepoint > 255) {
                return false;
            }

            return \chr($codepoint);
        }
        if (!self::isValidUnicodeCodepoint($codepoint)) {
            return false;
        }
        $utf8 = self::encodeUtf8Codepoint($codepoint);
        $converted = CharsetEngine::convert('UTF-8', $encoding, $utf8);

        return false === $converted ? false : $converted;
    }

    /**
     * mb_ord() — first character codepoint (php-src ext/mbstring/mbstring.c; #4559).
     */
    public static function ord(string $string, string $encoding): int|false
    {
        if ('' === $string) {
            throw new \ValueError('mb_ord(): Argument #1 ($string) must not be empty');
        }
        $encoding = MbstringEncodingRegistry::assertValid($encoding, 'mb_ord', 1);
        if ('UTF-8' === $encoding) {
            if (!VmString::isValidUtf8($string)) {
                return false;
            }
            $charLen = VmString::utf8CharLength($string);
            if (0 === $charLen) {
                return false;
            }

            return self::utf8CharToCodepoint(VmString::utf8CharSubstr($string, 0, 1));
        }
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return \ord($string[0]);
        }
        $utf8 = CharsetEngine::convert($encoding, 'UTF-8', $string[0]);
        if (false === $utf8 || !VmString::isValidUtf8($utf8)) {
            return false;
        }

        return self::utf8CharToCodepoint($utf8);
    }

    private static function isValidUnicodeCodepoint(int $cp): bool
    {
        if ($cp < 0 || $cp >= 0x110000) {
            return false;
        }
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            return false;
        }

        return true;
    }

    private static function assertNumericEntityEncoding(string $encoding): void
    {
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_encode_numericentity()/mb_decode_numericentity() require mbstring for encoding '
                .$encoding.' in this compiler build'
            );
        }
    }

    /**
     * mb_encode_mimeheader() — RFC 2047 encoded-word headers (php-src ext/mbstring/mbstring.c; #6038).
     */
    public static function encodeMimeheader(
        string $str,
        string $charset = 'UTF-8',
        bool $base64 = true,
        string $linefeed = "\r\n",
        int $indent = 0
    ): string {
        if ('' === $str) {
            return '';
        }
        self::assertMimeHeaderCharset($charset);
        if ($indent < 0 || $indent >= 74) {
            $indent = 0;
        }
        if (self::mimeHeaderCanPassThrough($str)) {
            return $str;
        }

        $parts = self::mimeHeaderSplitSegments($str);
        $out = '';
        foreach ($parts as $part) {
            if ('ascii' === $part['type']) {
                $out .= $part['text'];
                continue;
            }
            if ('' !== $out && !str_ends_with($out, ' ')) {
                $out .= ' ';
            }
            $out .= self::mimeHeaderEncodeWord($part['text'], $charset, $base64);
        }

        return $out;
    }

    /**
     * mb_decode_mimeheader() — decode RFC 2047 encoded words (php-src ext/mbstring/mbstring.c; #6038).
     */
    public static function decodeMimeheader(string $str): string
    {
        if ('' === $str) {
            return '';
        }

        $len = \strlen($str);
        $out = '';
        $i = 0;
        while ($i < $len) {
            if ('=' === $str[$i] && ($i + 1) < $len && '?' === $str[$i + 1]) {
                $decoded = self::mimeHeaderDecodeWordAt($str, $i, $len);
                if (null !== $decoded) {
                    [$text, $next] = $decoded;
                    $out .= $text;
                    $i = $next;
                    while ($i < $len && self::mimeHeaderIsWhitespace($str[$i])) {
                        ++$i;
                    }
                    if ($i < $len && '=' === $str[$i] && ($i + 1) < $len && '?' === $str[$i + 1]) {
                        continue;
                    }
                    if ($i < $len) {
                        $out .= ' ';
                    }
                    continue;
                }
            }

            $start = $i;
            while ($i < $len) {
                if ('=' === $str[$i] && ($i + 1) < $len && '?' === $str[$i + 1]) {
                    break;
                }
                if ("\n" === $str[$i] || "\r" === $str[$i]) {
                    ++$i;
                    while ($i < $len && self::mimeHeaderIsWhitespace($str[$i])) {
                        ++$i;
                    }
                    if ($i < $len) {
                        $out .= ' ';
                    }
                    break;
                }
                ++$i;
            }
            if ($i > $start) {
                $out .= \substr($str, $start, $i - $start);
            }
        }

        return $out;
    }

    public static function coerceMimeHeaderTransferEncoding(Variable $var, string $function, int $argIndex): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return true;
        }
        $name = VmString::coerceStringBuiltinArg($var, $function, $argIndex, 'transfer_encoding');
        if ('' === $name) {
            return true;
        }
        $flag = $name[0];

        return 'B' !== $flag && 'b' !== $flag ? false : true;
    }

    public static function coerceMimeHeaderLinefeed(Variable $var, string $function, int $argIndex): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return "\r\n";
        }

        return VmString::coerceStringBuiltinArg($var, $function, $argIndex, 'linefeed');
    }

    public static function coerceMimeHeaderIndent(Variable $var, string $function, int $argIndex): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($indent) must be of type int, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($indent) must be of type int, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($var)
            ));
        }

        return $var->toInt();
    }

    private static function assertMimeHeaderCharset(string $charset): void
    {
        if ('UTF-8' !== $charset && 'ASCII' !== $charset && '8BIT' !== $charset) {
            throw new \ValueError(\sprintf(
                'mb_encode_mimeheader(): Argument #2 ($charset) is not a supported encoding, "%s" given',
                $charset
            ));
        }
        if ('ASCII' === $charset || '8BIT' === $charset) {
            return;
        }
    }

    private static function mimeHeaderCanPassThrough(string $str): bool
    {
        $checkingLeading = true;
        $len = \strlen($str);
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($str[$i]);
            if ($checkingLeading && 0x20 === $byte) {
                continue;
            }
            $checkingLeading = false;
            if ($byte < 0x21 || $byte > 0x7E || 0x3D === $byte || 0x3F === $byte || 0x5F === $byte) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split into ascii pass-through prefix and encoded suffix (php-src mbfl mime header).
     *
     * @return list<array{type: 'ascii'|'encoded', text: string}>
     */
    private static function mimeHeaderSplitSegments(string $str): array
    {
        $len = \strlen($str);
        $encodeStart = null;
        for ($i = 0; $i < $len; ++$i) {
            if (!self::mimeHeaderIsSafeAsciiByte($str[$i])) {
                $encodeStart = $i;
                break;
            }
        }
        if (null === $encodeStart) {
            return [['type' => 'ascii', 'text' => $str]];
        }
        if (0 === $encodeStart) {
            return [['type' => 'encoded', 'text' => $str]];
        }
        $spacePos = null;
        for ($j = $encodeStart - 1; $j >= 0; --$j) {
            if (' ' === $str[$j]) {
                $spacePos = $j;
                break;
            }
        }
        if (null === $spacePos) {
            return [['type' => 'encoded', 'text' => $str]];
        }

        return [
            ['type' => 'ascii', 'text' => \substr($str, 0, $spacePos + 1)],
            ['type' => 'encoded', 'text' => \substr($str, $spacePos + 1)],
        ];
    }

    private static function mimeHeaderIsSafeAsciiByte(string $byte): bool
    {
        $ord = \ord($byte);

        return $ord >= 0x20 && $ord <= 0x7E && 0x3D !== $ord && 0x3F !== $ord && 0x5F !== $ord;
    }

    private static function mimeHeaderIsWhitespace(string $byte): bool
    {
        return ' ' === $byte || "\t" === $byte || "\r" === $byte || "\n" === $byte;
    }

    private static function mimeHeaderEncodeWord(string $text, string $charset, bool $base64): string
    {
        $mimeCharset = 'ASCII' === $charset || '8BIT' === $charset ? 'ISO-8859-1' : $charset;

        return $base64
            ? '=?'.$mimeCharset.'?B?'.\base64_encode($text).'?='
            : '=?'.$mimeCharset.'?Q?'.self::mimeHeaderQEncode($text).'?=';
    }

    private static function mimeHeaderQEncode(string $text): string
    {
        $out = '';
        $len = \strlen($text);
        for ($i = 0; $i < $len; ++$i) {
            $byte = $text[$i];
            $ord = \ord($byte);
            if ($ord >= 0x20 && $ord <= 0x7E && 0x3D !== $ord && 0x3F !== $ord && 0x5F !== $ord) {
                $out .= $byte;
                continue;
            }
            if (0x20 === $ord) {
                $out .= '_';
                continue;
            }
            $out .= \sprintf('=%02X', $ord);
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function mimeHeaderDecodeWordAt(string $str, int $pos, int $len): ?array
    {
        if (($pos + 5) >= $len || '=' !== $str[$pos] || '?' !== $str[$pos + 1]) {
            return null;
        }
        $charsetEnd = \strpos($str, '?', $pos + 2);
        if (false === $charsetEnd || ($charsetEnd + 2) >= $len) {
            return null;
        }
        $encoding = $str[$charsetEnd + 1];
        if ('?' !== $str[$charsetEnd + 2]) {
            return null;
        }
        $dataStart = $charsetEnd + 3;
        $dataEnd = \strpos($str, '?=', $dataStart);
        if (false === $dataEnd) {
            if ($len > $dataStart && '?' === $str[$len - 1]) {
                $dataEnd = $len - 1;
                $next = $len;
            } else {
                return null;
            }
        } else {
            $next = $dataEnd + 2;
        }
        $payload = \substr($str, $dataStart, $dataEnd - $dataStart);
        $decoded = ('Q' === $encoding || 'q' === $encoding)
            ? self::mimeHeaderQDecode($payload)
            : self::mimeHeaderBase64Decode($payload);

        return [$decoded, $next];
    }

    private static function mimeHeaderBase64Decode(string $payload): string
    {
        $clean = \preg_replace('/[\r\n\t =]/', '', $payload);
        if (!\is_string($clean) || '' === $clean) {
            return '';
        }
        $decoded = \base64_decode($clean, true);

        return false === $decoded ? '' : $decoded;
    }

    private static function mimeHeaderQDecode(string $payload): string
    {
        $out = '';
        $len = \strlen($payload);
        for ($i = 0; $i < $len; ++$i) {
            $byte = $payload[$i];
            if ('_' === $byte) {
                $out .= ' ';
                continue;
            }
            if ('=' === $byte && ($i + 2) < $len) {
                $hex = \hexdec(\substr($payload, $i + 1, 2));
                $out .= \chr((int) $hex);
                $i += 2;
                continue;
            }
            $out .= $byte;
        }

        return $out;
    }

    /**
     * mb_str_split() — split string into multibyte chunks (php-src ext/mbstring/mbstring.c; #3299).
     *
     * @return list<string>
     */
    public static function strSplit(string $string, int $length = 1, string $encoding = 'UTF-8'): array
    {
        if ($length <= 0) {
            throw new \ValueError('mb_str_split(): Argument #2 ($length) must be greater than 0');
        }
        $encoding = self::assertSubstrCountEncoding($encoding, 'mb_str_split', 2);
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            return self::strSplitSingleByte($string, $length);
        }
        $charLen = VmString::utf8CharLength($string);
        if (0 === $charLen) {
            return [];
        }
        $result = [];
        for ($i = 0; $i < $charLen; $i += $length) {
            $result[] = VmString::utf8CharSubstr($string, $i, min($length, $charLen - $i));
        }

        return $result;
    }

    /** @return list<string> */
    private static function strSplitSingleByte(string $string, int $length): array
    {
        $byteLen = VmString::byteLength($string);
        if (0 === $byteLen) {
            return [];
        }
        $result = [];
        for ($i = 0; $i < $byteLen; $i += $length) {
            $result[] = \substr($string, $i, min($length, $byteLen - $i));
        }

        return $result;
    }

    /**
     * mb_split() — multibyte regex split (php-src ext/mbstring/php_mbregex.c; #13367).
     *
     * UTF-8 / ASCII via PCRE u-flag; Onig-specific patterns may differ from Zend.
     *
     * @return array<int, string>|false
     */
    public static function split(string $pattern, string $string, int $limit = -1): array|false
    {
        if (!self::checkEncoding($string, 'UTF-8')) {
            return false;
        }

        $regex = self::mbSplitRegex($pattern);
        if (null === $regex) {
            return false;
        }

        @preg_match($regex, '');
        if (PREG_NO_ERROR !== preg_last_error()) {
            return false;
        }

        $parts = preg_split($regex, $string, $limit > 0 ? $limit : -1);
        if (false === $parts) {
            return false;
        }

        return $parts;
    }

    public static function mbSplitRegexCompileError(string $pattern): ?string
    {
        $regex = self::mbSplitRegex($pattern);
        if (null === $regex) {
            return 'invalid pattern delimiter';
        }
        @preg_match($regex, '');

        return PREG_NO_ERROR === preg_last_error() ? null : preg_last_error_msg();
    }

    public static function warnMbSplitRegexFailure(Frame $frame, string $pattern): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $detail = self::mbSplitRegexCompileError($pattern) ?? 'invalid pattern';
        $frame->vmContext->errors->triggerErrorWithHandlerFirst(
            'mb_split(): mbregex compile err: '.$detail,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function mbSplitRegex(string $pattern): ?string
    {
        if ('' === $pattern) {
            return null;
        }

        return self::mbEregRegex($pattern, false);
    }

    /**
     * Z_PARAM_STR $pattern for mb_ereg / mb_eregi (php-src php_mbregex.c; #20261).
     *
     * Null: TypeError on PROFILE=8.4; deprecate+coerce to "" on default profile.
     * Empty string (after coerce): ValueError — must not be empty.
     */
    public static function coerceMbEregPatternArg(Frame $frame, string $function, int $argIndex = 0): string
    {
        $pattern = VmString::zparamStrBuiltinArgForFrame(
            $frame,
            $argIndex,
            $function,
            $argIndex,
            'pattern'
        );
        if ('' === $pattern) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($pattern) must not be empty',
                $function,
                $argIndex + 1
            ));
        }

        return $pattern;
    }

    /**
     * Build PCRE pattern for mb_ereg* (php-src ext/mbstring/php_mbregex.c; #4635, #20024).
     *
     * Oniguruma semantics are approximated via PCRE u-flag (same approach as mb_split).
     */
    public static function mbEregRegex(
        string $pattern,
        bool $caseInsensitive,
        ?string $optionsOverride = null,
        bool $anchored = false
    ): ?string {
        if ('' === $pattern && !$anchored) {
            // Empty pattern still compiles in some Onig modes; keep delimiter form.
        }

        return '#'.$pattern.'#'.self::mbEregPcreSuffix($caseInsensitive, $optionsOverride, $anchored);
    }

    public static function optionsImplyIgnoreCase(?string $options): bool
    {
        if (null === $options) {
            return false;
        }

        return str_contains($options, 'i') || str_contains($options, 'I');
    }

    /**
     * @return array{matched: bool, registers: array<int, string>}
     */
    public static function eregMatch(
        string $pattern,
        string $string,
        bool $caseInsensitive,
        ?string $optionsOverride = null,
        bool $anchored = false
    ): array {
        if (!self::checkEncoding($string, MbstringState::regexEncoding())) {
            return ['matched' => false, 'registers' => []];
        }

        $ci = $caseInsensitive || self::optionsImplyIgnoreCase($optionsOverride);
        $regex = self::mbEregRegex($pattern, $ci, $optionsOverride, $anchored);
        if (null === $regex) {
            return ['matched' => false, 'registers' => []];
        }

        $matches = [];
        $result = @preg_match($regex, $string, $matches);
        if (false === $result || PREG_NO_ERROR !== preg_last_error()) {
            return ['matched' => false, 'registers' => []];
        }
        if (0 === $result) {
            return ['matched' => false, 'registers' => []];
        }

        return ['matched' => true, 'registers' => $matches];
    }

    /**
     * Write mb_ereg / mb_eregi by-ref $regs (php-src _php_mb_regex_ereg_exec; #26408).
     *
     * Zend always assigns an array when argc≥3: capture groups on match, empty array on no-match.
     *
     * @param array{matched: bool, registers: array<int, string>} $out
     */
    public static function writeEregRegistersArg(Variable $target, array $out): void
    {
        if ($out['matched']) {
            $target->array(VmPregMatches::hostMatchesToHashTable($out['registers'], 0));

            return;
        }

        $target->array(new HashTable());
    }

    /**
     * mb_ereg_match() — match only at start of string (onig_match; #20024).
     */
    public static function eregMatchAnchored(
        string $pattern,
        string $string,
        ?string $options = null
    ): bool {
        $out = self::eregMatch($pattern, $string, false, $options, true);

        return $out['matched'];
    }

    public static function eregReplace(
        string $pattern,
        string $replacement,
        string $string,
        bool $caseInsensitive,
        ?string $optionsOverride = null
    ): string|false|null {
        if (!self::checkEncoding($string, MbstringState::regexEncoding())) {
            return null;
        }

        $ci = $caseInsensitive || self::optionsImplyIgnoreCase($optionsOverride);
        $regex = self::mbEregRegex($pattern, $ci, $optionsOverride);
        if (null === $regex) {
            return false;
        }

        $result = @preg_replace($regex, $replacement, $string);
        if (null === $result) {
            return false;
        }
        if (PREG_NO_ERROR !== preg_last_error()) {
            return false;
        }

        return $result;
    }

    /**
     * mb_ereg_search_init() (php-src php_mbregex.c; #20024).
     */
    public static function eregSearchInit(
        string $string,
        ?string $pattern = null,
        ?string $options = null
    ): bool {
        if (null !== $pattern && '' === $pattern) {
            throw new \ValueError('mb_ereg_search_init(): Argument #2 ($pattern) must not be empty');
        }

        if (null !== $pattern) {
            $ci = self::optionsImplyIgnoreCase($options);
            $regex = self::mbEregRegex($pattern, $ci, $options);
            if (null === $regex) {
                return false;
            }
            @preg_match($regex, '');
            if (PREG_NO_ERROR !== preg_last_error()) {
                return false;
            }
            MbstringState::setSearchPattern($pattern, $ci, $options);
        }

        MbstringState::setSearchString($string);
        MbstringState::setSearchRegs(null);

        if (self::checkEncoding($string, MbstringState::regexEncoding())) {
            MbstringState::setSearchPos(0);

            return true;
        }

        MbstringState::setSearchPos(\strlen($string));

        return false;
    }

    /**
     * mb_ereg_search / search_pos / search_regs shared exec (php-src _php_mb_regex_ereg_search_exec; #20024).
     *
     * @return bool|array<int, int|string|false>
     */
    public static function eregSearchExec(int $mode, ?string $pattern = null, ?string $options = null): bool|array
    {
        MbstringState::setSearchRegs(null);

        if (null !== $pattern) {
            $ci = self::optionsImplyIgnoreCase($options);
            $regex = self::mbEregRegex($pattern, $ci, $options);
            if (null === $regex) {
                return false;
            }
            @preg_match($regex, '');
            if (PREG_NO_ERROR !== preg_last_error()) {
                return false;
            }
            MbstringState::setSearchPattern($pattern, $ci, $options);
        }

        if (null === MbstringState::searchPattern()) {
            throw new \Error('No pattern was provided');
        }
        $str = MbstringState::searchString();
        if (null === $str) {
            throw new \Error('No string was provided');
        }

        $pos = MbstringState::searchPos();
        $len = \strlen($str);
        $ci = MbstringState::searchCaseInsensitive();
        $optOverride = MbstringState::searchOptionsOverride();
        $regex = self::mbEregRegex(MbstringState::searchPattern(), $ci, $optOverride);
        if (null === $regex) {
            MbstringState::setSearchPos($len);

            return false;
        }

        $matches = [];
        $result = @preg_match($regex, $str, $matches, \PREG_OFFSET_CAPTURE, $pos);
        if (false === $result || PREG_NO_ERROR !== preg_last_error() || 0 === $result) {
            MbstringState::setSearchPos($len);

            return false;
        }

        $regs = self::offsetCaptureToMbRegs($matches);
        MbstringState::setSearchRegs($regs);

        $beg = (int) $matches[0][1];
        $matchText = (string) $matches[0][0];
        $end = $beg + \strlen($matchText);
        if ($pos <= $end) {
            MbstringState::setSearchPos($end);
        } else {
            MbstringState::setSearchPos($pos + 1);
        }

        return match ($mode) {
            1 => [$beg, $end - $beg],
            2 => $regs,
            default => true,
        };
    }

    /**
     * @return array<int, string|false>|false
     */
    public static function eregSearchGetRegs(): array|false
    {
        $regs = MbstringState::searchRegs();
        if (null === $regs || null === MbstringState::searchString()) {
            return false;
        }

        return $regs;
    }

    public static function eregSearchSetPos(int $position): bool
    {
        $str = MbstringState::searchString();
        if ($position < 0 && null !== $str) {
            $position += \strlen($str);
        }
        if ($position < 0 || (null !== $str && $position > \strlen($str))) {
            throw new \ValueError('mb_ereg_search_setpos(): Argument #1 ($offset) is out of range');
        }
        MbstringState::setSearchPos($position);

        return true;
    }

    /**
     * mb_ereg_replace_callback() (php-src _php_mb_regex_ereg_replace_exec is_callable; #20024).
     *
     * @return string|false|null
     */
    public static function eregReplaceCallback(
        \PHPCompiler\VM\Context $vmContext,
        string $pattern,
        Variable $callback,
        string $string,
        ?string $options = null
    ): string|false|null {
        if (!self::checkEncoding($string, MbstringState::regexEncoding())) {
            return null;
        }

        $ci = self::optionsImplyIgnoreCase($options);
        $regex = self::mbEregRegex($pattern, $ci, $options);
        if (null === $regex) {
            return false;
        }

        if (!VmCallable::isCallable($vmContext, $callback)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('mb_ereg_replace_callback'));
        }

        $result = '';
        $offset = 0;
        $len = \strlen($string);

        while ($offset <= $len) {
            $matches = [];
            $matchCount = @preg_match($regex, $string, $matches, \PREG_OFFSET_CAPTURE, $offset);
            if (false === $matchCount || PREG_NO_ERROR !== preg_last_error()) {
                return false;
            }
            if (0 === $matchCount) {
                $result .= \substr($string, $offset);

                break;
            }

            $matchStart = (int) $matches[0][1];
            $matchText = (string) $matches[0][0];
            $matchLen = \strlen($matchText);
            $result .= \substr($string, $offset, $matchStart - $offset);

            $regs = self::offsetCaptureToMbRegs($matches);
            $matchesVar = new Variable();
            $matchesVar->array(self::mbRegsToHashTable($regs));
            $replacement = VmCallable::invokeAs(
                'mb_ereg_replace_callback',
                $vmContext,
                $callback,
                $matchesVar
            );
            $result .= $vmContext->runtime->vm->coerceVariableToString($replacement->resolveIndirect());

            $next = $matchStart + $matchLen;
            if ($next <= $offset) {
                if ($offset < $len) {
                    $result .= $string[$offset];
                }
                $offset++;
            } else {
                $offset = $next;
            }
            if ($offset >= $len) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param array<int|string, array{0: string, 1: int}> $matches
     *
     * @return array<int, string|false>
     */
    private static function offsetCaptureToMbRegs(array $matches): array
    {
        $regs = [];
        foreach ($matches as $key => $entry) {
            if (!\is_int($key)) {
                continue;
            }
            if (!\is_array($entry) || !\array_key_exists(0, $entry) || !\array_key_exists(1, $entry)) {
                $regs[$key] = false;
                continue;
            }
            $beg = (int) $entry[1];
            if ($beg < 0) {
                $regs[$key] = false;
                continue;
            }
            $regs[$key] = (string) $entry[0];
        }

        return $regs;
    }

    /**
     * @param array<int, string|false> $regs
     */
    public static function mbRegsToHashTable(array $regs): HashTable
    {
        $ht = new HashTable();
        foreach ($regs as $key => $value) {
            $slot = new Variable();
            if (false === $value) {
                $slot->bool(false);
            } else {
                $slot->string($value);
            }
            $ht->updateIndex((int) $key, $slot);
        }

        return $ht;
    }

    /**
     * @param array<int, int> $pair
     */
    public static function searchPosPairToHashTable(array $pair): HashTable
    {
        $ht = new HashTable();
        foreach ($pair as $value) {
            $slot = new Variable();
            $slot->int((int) $value);
            $ht->append($slot);
        }

        return $ht;
    }

    /**
     * mb_send_mail() VM entry (php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_send_mail); #6548).
     */
    public static function runSendMailBuiltin(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'mb_send_mail() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $to = VmString::coercePathBuiltinArg($frame->calledArgs[0], 'mb_send_mail', 0, 'to');
        $subject = VmString::coercePathBuiltinArg($frame->calledArgs[1], 'mb_send_mail', 1, 'subject');
        $message = VmString::coercePathBuiltinArg($frame->calledArgs[2], 'mb_send_mail', 2, 'message');
        $headersArg = $argc >= 4 ? $frame->calledArgs[3] : null;
        $extraParams = null;
        if ($argc >= 5) {
            $extraParams = VmString::coercePathBuiltinArg(
                $frame->calledArgs[4],
                'mb_send_mail',
                4,
                'additional_params'
            );
        }

        $prepared = self::prepareSendMail(
            $to,
            $subject,
            $message,
            $headersArg,
            $extraParams
        );
        self::dispatchMailTransport($frame, $prepared);
    }

    /**
     * @return array{to: string, subject: string, message: string, headers: string, params: ?string}
     */
    public static function prepareSendMail(
        string $to,
        string $subject,
        string $message,
        ?Variable $headersArg = null,
        ?string $extraParams = null
    ): array {
        $profile = MbstringMailProfile::forLanguage(MbstringState::language());
        $mailCharset = $profile['charset'];
        $headerBase64 = 'base64' === $profile['header'];
        $bodyEncoding = $profile['body'];

        $to = self::normalizeMailRecipient($to);
        $subject = self::encodeMimeheader(
            self::convertEncoding($subject, $mailCharset, MbstringState::internalEncoding()) ?: $subject,
            $mailCharset,
            $headerBase64
        );
        $converted = self::convertEncoding($message, $mailCharset, MbstringState::internalEncoding());
        $message = false === $converted ? $message : $converted;
        $message = self::applyMailBodyEncoding($message, $bodyEncoding);

        [$headersText, $suppressContentType, $suppressTransferEncoding] = self::coerceSendMailHeaders($headersArg);
        $headers = self::buildSendMailHeaders(
            $headersText,
            $mailCharset,
            $bodyEncoding,
            $suppressContentType,
            $suppressTransferEncoding
        );

        return [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'params' => $extraParams,
        ];
    }

    /**
     * @return array{0: string, 1: bool, 2: bool}
     */
    private static function coerceSendMailHeaders(?Variable $headersArg): array
    {
        if (null === $headersArg) {
            return ['', false, false];
        }

        $headersArg = $headersArg->resolveIndirect();
        if (Variable::TYPE_NULL === $headersArg->type) {
            return ['', false, false];
        }
        if (Variable::TYPE_STRING === $headersArg->type) {
            $headers = $headersArg->toString();
            VmString::rejectNullByteBuiltinStringArg($headers, 'mb_send_mail', 3, 'additional_headers');
            $headers = trim($headers);

            return [$headers, self::sendMailHeaderHas($headers, 'content-type'), self::sendMailHeaderHas($headers, 'content-transfer-encoding')];
        }
        if (Variable::TYPE_ARRAY !== $headersArg->type) {
            throw new \TypeError(\sprintf(
                'mb_send_mail(): Argument #4 ($additional_headers) must be of type array|string, %s given',
                self::typeLabel($headersArg)
            ));
        }

        return [self::buildMailHeadersFromArray($headersArg->toArray()), false, false];
    }

    private static function buildMailHeadersFromArray(\PHPCompiler\VM\HashTable $headers): string
    {
        $lines = [];
        foreach ($headers->iterateKeyed(true) as [$key, $value]) {
            $value = $value->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($value)) {
                throw new \TypeError(\sprintf(
                    'mb_send_mail(): Argument #4 ($additional_headers) must be of type array|string, %s given',
                    EnumCaseSupport::typeNameForVariable($value)
                ));
            }
            if (Variable::TYPE_STRING !== $value->type) {
                throw new \TypeError(\sprintf(
                    'mb_send_mail(): Argument #4 ($additional_headers) must be of type array|string, %s given',
                    self::typeLabel($value)
                ));
            }
            $line = $value->toString();
            VmString::rejectNullByteBuiltinStringArg($line, 'mb_send_mail', 3, 'additional_headers');
            if (\is_int($key) || (\is_string($key) && ctype_digit($key))) {
                $lines[] = $line;
                continue;
            }
            $lines[] = $key.': '.$line;
        }

        return implode("\r\n", $lines);
    }

    private static function buildSendMailHeaders(
        string $headersText,
        string $mailCharset,
        string $bodyEncoding,
        bool $suppressContentType,
        bool $suppressTransferEncoding
    ): string {
        $parts = [];
        if ('' !== $headersText) {
            $parts[] = rtrim(str_replace(["\r\n", "\n"], "\r\n", $headersText), "\r\n");
        }
        if (!self::sendMailHeaderHas($headersText, 'mime-version')) {
            $parts[] = 'MIME-Version: 1.0';
        }
        if (!$suppressContentType) {
            $parts[] = 'Content-Type: text/plain; charset='.$mailCharset;
        }
        if (!$suppressTransferEncoding) {
            $parts[] = 'Content-Transfer-Encoding: '.$bodyEncoding;
        }

        return implode("\r\n", array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    private static function sendMailHeaderHas(string $headers, string $name): bool
    {
        return 1 === preg_match('/^'.preg_quote($name, '/').'\s*:/mi', $headers);
    }

    private static function normalizeMailRecipient(string $to): string
    {
        if ('' === $to) {
            return '';
        }
        $to = rtrim($to);
        $len = \strlen($to);
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $byte = $to[$i];
            if ($byte < "\x20" || "\x7F" === $byte) {
                if ("\r" === $byte && ($i + 2) < $len && "\n" === $to[$i + 1]
                    && (' ' === $to[$i + 2] || "\t" === $to[$i + 2])) {
                    $i += 2;
                    while (($i + 1) < $len && (' ' === $to[$i + 1] || "\t" === $to[$i + 1])) {
                        ++$i;
                    }
                    continue;
                }
                $out .= ' ';
                continue;
            }
            $out .= $byte;
        }

        return $out;
    }

    private static function applyMailBodyEncoding(string $message, string $bodyEncoding): string
    {
        return match (strtolower($bodyEncoding)) {
            'base64' => rtrim(chunk_split(base64_encode($message), 76, "\r\n"), "\r\n"),
            default => $message,
        };
    }

    /**
     * @param array{to: string, subject: string, message: string, headers: string, params: ?string} $prepared
     */
    private static function dispatchMailTransport(Frame $frame, array $prepared): void
    {
        $frame->calledArgs = [
            self::stringVariable($prepared['to']),
            self::stringVariable($prepared['subject']),
            self::stringVariable($prepared['message']),
            self::stringVariable($prepared['headers']),
        ];
        if (null !== $prepared['params']) {
            $frame->calledArgs[] = self::stringVariable($prepared['params']);
        }
        (new MailBuiltin())->execute($frame);
    }

    private static function stringVariable(string $value): Variable
    {
        $var = new Variable();
        $var->string($value);

        return $var;
    }

    public static function mbEregRegexCompileError(string $pattern, bool $caseInsensitive): ?string
    {
        $regex = self::mbEregRegex($pattern, $caseInsensitive);
        if (null === $regex) {
            return 'invalid pattern';
        }
        @preg_match($regex, '');

        return PREG_NO_ERROR === preg_last_error() ? null : preg_last_error_msg();
    }

    public static function warnMbEregRegexFailure(
        Frame $frame,
        string $function,
        string $pattern,
        bool $caseInsensitive
    ): void {
        if (null === $frame->vmContext) {
            return;
        }
        $detail = self::mbEregRegexCompileError($pattern, $caseInsensitive) ?? 'invalid pattern';
        $frame->vmContext->errors->triggerErrorWithHandlerFirst(
            $function.'(): mbregex compile err: '.$detail,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function mbEregPcreSuffix(
        bool $caseInsensitive,
        ?string $optionsOverride = null,
        bool $anchored = false
    ): string {
        $flags = 'u';
        if ($caseInsensitive) {
            $flags .= 'i';
        }
        $options = $optionsOverride ?? MbstringState::regexOptions();
        foreach (str_split($options) as $option) {
            if ('i' === $option || 'I' === $option) {
                if (!str_contains($flags, 'i')) {
                    $flags .= 'i';
                }
                continue;
            }
            if (\in_array($option, ['m', 's', 'x', 'U'], true) && !str_contains($flags, $option)) {
                $flags .= $option;
            }
        }
        if ($anchored && !str_contains($flags, 'A')) {
            $flags .= 'A';
        }

        return $flags;
    }
}
