<?php

declare(strict_types=1);

/**
 * VM-runtime string helpers for the standard library (no PHP userland builtins).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\RuntimeStrictness;
use PHPCompiler\VM;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

final class VmString
{
    /**
     * Z_PARAM_STR null coercion — php-src coerces to "" outside caller strict_types (#19161).
     *
     * Forward profile 8.4 does not add a profile-wide null TypeError; only {@see InternalStrictArg}
     * at strict call sites rejects null before coercion (ext/standard/string.c).
     */
    public static function requiresForwardProfileStrictStringNull(): bool
    {
        return false;
    }

    /**
     * Z_PARAM_STR typed operands — null TypeError on 8.4 forward profile (#18840, #18980, #19222, #19254, #19318).
     *
     * Distinct from {@see requiresForwardProfileStrictStringNull} (legacy global switch, currently off).
     * wordwrap/str_pad and other typed string builtins use this guard (php-src ext/standard/string.c).
     * str_repeat/str_shuffle/ucfirst/lcfirst/ucwords soft-null on 8.4 (#24598, reverts #24213/#20080).
     * str_increment/str_decrement soft-null then empty ValueError (#26264, reverts #21005 TypeError).
     * strlen/strtolower/strtoupper/strrev, trim/ltrim/rtrim/chop (#21404, reverts #21350),
     * and md5/sha1/crc32/bin2hex/hash($data)/hash_hmac($data)/hash_hmac($key)/hash_update($data) coerce null with
     * deprecation on forward profile (php_trim / string.c / hash.c, re-#18850 #19983 #19998 #20007 #21181 #21209 #21557).
     * base64_encode/base64_decode, urlencode/urldecode/rawurlencode/rawurldecode, parse_url soft-null (#21188).
     * mb_strlen/mb_substr/mb_strpos and iconv/iconv_strlen(+substr/strpos/strrpos input) soft-null (#21197).
     * mb_trim/mb_ltrim/mb_rtrim/mb_ucfirst/mb_lcfirst/mb_str_pad soft-null (#24176, reverts #17132/#19433/#19184).
     * preg_match/preg_replace/preg_split/preg_match_all/preg_replace_callback*
     * $subject (and str_replace family $subject) soft-null likewise (#21198, #21318).
     * preg_match/preg_match_all/preg_split/preg_grep $pattern soft-null (#21479, reverts #20226).
     * json_decode/json_validate $json soft-null (#21223, #28333; reverts #27995 TypeError claim).
     * parse_ini_string $ini_string soft-null (#21431, reverts #18658).
     * parse_str $string soft-null (#21480, reverts #21380 TypeError).
     * trigger_error/user_error $message soft-null (#21480, reverts #21035 TypeError).
     * introspection name args (function_exists/class_exists/defined/…) soft-null (#21281).
     * htmlspecialchars/htmlentities/nl2br/addslashes soft-null on 8.4 (#21405/#21406; reverts #21351 TypeError).
     * convert_uudecode soft-null on 8.4 (#21420; empty decode → warning+false like Zend).
     * substr_compare soft-null on 8.4 (#21515, reverts #20164 TypeError; peers strncmp #21317).
     * glob()/fnmatch() pattern soft-null on 8.4 (#21366, ext/standard/file.c, fnmatch.c).
     * fsockopen/stream_socket_client (#23823) Z_PARAM_STR null TypeError on 8.4.
     * pfsockopen hostname Z_PARAM_STR null TypeError on 8.4 (#23858 / #23823).
     */
    public static function requiresZparamStrStrictNullOnForwardProfile(): bool
    {
        return version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * filestat/path builtins — stub `string $filename` rejects int/float/bool on 8.4+ (#5122, ext/standard/filestat.c).
     */
    public static function requiresTypedPathStringOnForwardProfile(): bool
    {
        return version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * String builtins that coerce null with deprecation (not Z_PARAM_STR TypeError on 8.4).
     *
     * str_repeat/str_shuffle/ucfirst/lcfirst/ucwords soft-null (#24598, reverts #24213/#20080).
     * str_increment/str_decrement soft-null then empty ValueError (#26264, reverts #21005 TypeError).
     * Used by strlen/strtolower/strtoupper/strrev (#20007), trim/ltrim/rtrim/chop (#21404),
     * md5/sha1/crc32/bin2hex/hash($data) (#21181),
     * hash_hmac($data)/hash_hmac($key)/hash_update($data) (#21209, #21557),
     * stripslashes/addcslashes/stripcslashes/quotemeta (+ decode siblings) (#21180), str_contains/str_starts_with/str_ends_with (#21187),
     * base64 encode/decode, url encode/decode, parse_url (#21188),
     * mb_strlen/mb_substr/mb_strpos + iconv/iconv_* string inputs (#21197),
     * mb_strtolower/mb_convert_encoding/mb_substr_count string inputs (#21282),
     * mb_strcut/mb_strimwidth/mb_encode_mimeheader string inputs (#21430),
     * mb_scrub/mb_detect_encoding string inputs (#21516, reverts #21061/#20225 TypeError),
     * mb_trim/mb_ltrim/mb_rtrim/mb_ucfirst/mb_lcfirst/mb_str_pad string inputs (#24176, reverts #17132/#19433/#19184 TypeError),
     * function_exists/class_exists/interface_exists/trait_exists/enum_exists/
     * extension_loaded/defined/constant/method_exists/property_exists/define name args (#21281),
     * preg_match/preg_split/preg_match_all $subject (#21198, #21318), and substr/strpos/strstr/explode string
     * operands (#21189), stripos/strripos/strrpos/stristr/strchr/strrchr/strpbrk haystack (#21444),
     * preg_match/preg_match_all/preg_split/preg_grep $pattern (#21479, reverts #20226),
     * substr_count/substr_replace haystack (#21196), ord() character (#21222),
     * chunk_split/str_pad/wordwrap/soundex/metaphone/strcmp/strcasecmp (#21190),
     * levenshtein/similar_text/strcspn/strspn/strtok($string) (#21195),
     * strncmp/strncasecmp/strnatcmp/strnatcasecmp/strcoll (#21317),
     * substr_compare haystack/needle (#21515, reverts #20164 TypeError),
     * json_decode/json_validate $json, unserialize $data (#21223).
     * parse_ini_string $ini_string soft-null (#21431, reverts #18658).
     * hex2bin/convert_uuencode/convert_uudecode/sscanf($string/$format), pack($values) soft-null (#21209/#21420/#21521).
     * pack()/unpack() $format soft-null (#21478, reverts #20241 TypeError).
     * unpack($string) soft-null (#21246).
     * escapeshellarg/escapeshellcmd soft-null (#21221, re-#19333).
     * setcookie/setrawcookie $name soft-null (#21233, re-#21003).
     * date/gmdate $format and strtotime $datetime soft-null (#21208, reverts #19651).
     * idate $format soft-null (#21491, reverts #20227 TypeError).
     * date_parse $datetime soft-null (#24862, reverts #20227 TypeError; ext/date/php_date.c).
     * DateTime::format()/date_format() $format soft-null (#21536, reverts #20693 TypeError).
     * timezone_open/DateTimeZone/date_default_timezone_set timezone id soft-null (#21369, ext/date/php_date.stub.php).
     * password_verify/password_needs_rehash/password_hash/password_get_info($hash) string operands soft-null (#21314/#21210/#21537; hash_equals stays TypeError).
     * hash()/hash_hmac()/hash_file()/hash_init() $algo soft-null (#21490/#21572, reverts #20304 TypeError).
     * version_compare($version1/$version2) soft-null (#21556, reverts #20254 TypeError; ext/standard/versioning.c).
     * getimagesizefromstring($string) soft-null (#21492, reverts #20353 TypeError; ext/standard/image.c).
     * hash_pbkdf2($algo/$password/$salt) and hash_hkdf($algo/$key/$info/$salt) soft-null (#21319, reverts #20659/#21079).
     * str_rot13/crypt/uniqid/gzcompress soft-null (#21280).
     * hebrev($string) soft-null (#21421, ext/standard/string.c).
     * dechex/decbin/decoct $num, hexdec/bindec/octdec/base_convert string operands soft-null (#21244).
     * zlib one-shot $data (gzdeflate/gzinflate/gzdecode/gzuncompress/gzcompress/gzencode) soft-null (#21311, reverts #19332).
     * openssl_encrypt/openssl_decrypt $data soft-null (#21445, reverts #20263; ext/openssl/openssl.c).
     * openssl_digest($data) soft-null (#21517, reverts #20207; ext/openssl/openssl.c).
     * sodium_bin2hex($string) / sodium_hex2bin($string,$ignore) soft-null (#21517/#24772, reverts #20196; ext/sodium).
     * implode/join $separator soft-null (#21210, reverts #19894).
     * header($header), preg_quote($str), printf/fprintf($format) soft-null (#21234, reverts #19224/#20197).
     * vprintf/vfprintf($format) soft-null (#21514, reverts over-strict requireStringBuiltinArg).
     * xml_parse/xml_parse_into_struct $data soft-null (#21505, ext/xml/xml.c).
     * simplexml_load_string($data) soft-null (#21502, reverts #20352 TypeError; ext/simplexml/simplexml.c).
     * token_get_all($code) soft-null (#21503, reverts #19894; ext/tokenizer/tokenizer.c).
     * ini_get/ini_set $option and putenv $assignment soft-null (#21312, reverts #20361/#21004 TypeError).
     * parse_str $string / trigger_error|user_error $message soft-null (#21480).
     * error_log($message), gethostbyname($hostname), dns_get_record($hostname) soft-null (#24965, re-#24178, reverts #23858).
     * gethostbynamel($hostname) soft-null (#24966, sibling of #24965; php-src ext/standard/dns.c).
     * gettext/_/dgettext/ngettext msgid + domain soft-null (#21581, reverts #20209 TypeError; ext/gettext/gettext.c).
     */
    public static function coerceTrimFamilyStringArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'string'
    ): string {
        return self::coerceStringBuiltinArg($var, $function, $argIndex, $paramName, 'string', false);
    }

    /**
     * Frame wrapper for {@see coerceTrimFamilyStringArg} — honors caller strict_types (#19998).
     */
    public static function trimFamilyStringArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $paramName
    ): string {
        return self::stringBuiltinArgForFrame(
            $frame,
            $argIndex,
            $function,
            $userArgIndex,
            $paramName,
            false
        );
    }

    public const TRIM_DEFAULT = " \t\n\r\0\x0B";

    /** php-src php_trim_int(): trim left side. */
    public const TRIM_SIDE_LEFT = 1;

    /** php-src php_trim_int(): trim right side. */
    public const TRIM_SIDE_RIGHT = 2;

    /** php-src php_trim_int(): trim left and right. */
    public const TRIM_SIDE_BOTH = 3;

    /**
     * Coerce a string builtin operand to string (php-src _convert_to_string parity, #3549, #4284).
     *
     * Objects with __toString invoke the magic method so exceptions reach enclosing try/catch.
     */
    public static function coerceOperand(Variable $var): string
    {
        $vm = VM::running();
        if (null !== $vm) {
            return $vm->coerceVariableToString($var);
        }

        return $var->resolveIndirect()->toString();
    }

    /**
     * str_replace / str_ireplace / substr_replace array *element* conversion (#29309).
     *
     * php-src uses convert_to_string / zval_get_string on elements — null becomes "" with no
     * parameter-level E_DEPRECATED. Do not route through {@see coerceStringBuiltinArg} (Z_PARAM_STR).
     * php-src: ext/standard/string.c php_str_replace_common / php_str_to_str_ex.
     */
    public static function coerceStrReplaceArrayElement(Variable $var): string
    {
        return self::coerceOperand($var);
    }

    /**
     * php_strtr_array() replace_pairs key/value — zend convert_to_string, not Z_PARAM_STR (#28978).
     *
     * Nested arrays warn "Array to string conversion" and become "Array"; objects without
     * __toString throw Error (not TypeError). php-src: ext/standard/string.c php_strtr_array().
     */
    public static function coerceStrtrReplacePairOperand(Variable $var, ?Frame $frame = null): string
    {
        $var = $var->resolveIndirect();
        $vm = VM::running();
        if (null !== $vm) {
            return $vm->coerceVariableToString($var, $frame);
        }

        return $var->toString(null, $frame);
    }

    /**
     * zend_parse_parameters "C" class-name operand (#30060).
     *
     * Null converts to "" with no Z_PARAM_STR E_DEPRECATED; class lookup then TypeErrors
     * "must be a valid class name,  given". Other operands keep Z_PARAM_STR guards.
     * php-src: Zend/zend_builtin_functions.c — ZEND_FUNCTION(get_class_vars) / "C".
     */
    public static function coerceClassNameParamArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'class'
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return '';
        }

        return self::coerceStringBuiltinArg($var, $function, $argIndex, $paramName);
    }

    /**
     * Coerce a typed string builtin operand (php-src IS_STRING; rejects null, #12640).
     *
     * @throws \TypeError when the operand is null or cannot be converted like Zend PHP 8.x
     */
    public static function coerceTypedStringBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'string'
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            throw new \TypeError(self::stringBuiltinTypeError($function, $argIndex, $paramName, 'null'));
        }

        return self::coerceStringBuiltinArg($var, $function, $argIndex, $paramName);
    }

    /**
     * Coerce a string builtin operand (php-src Z_PARAM_STR; rejects array / plain object, #4553).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    /**
     * Z_PARAM_STR — null TypeError on 8.4 forward profile (#18837, #18838, ext/standard/string.c).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coerceZparamStrBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'string',
        string $expectedType = 'string'
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            if (self::requiresZparamStrStrictNullOnForwardProfile()) {
                throw new \TypeError(
                    self::stringBuiltinTypeError($function, $argIndex, $paramName, 'null', $expectedType)
                );
            }
            VmNullStringParamDeprecation::emit(null, $function, $argIndex, $paramName, $expectedType);

            return '';
        }

        return self::coerceStringBuiltinArg(
            $var,
            $function,
            $argIndex,
            $paramName,
            $expectedType,
            false
        );
    }

    public static function coerceStringBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'string',
        string $expectedType = 'string',
        bool $rejectNullOnForwardProfile = true
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            if ($rejectNullOnForwardProfile && self::requiresForwardProfileStrictStringNull()) {
                throw new \TypeError(
                    self::stringBuiltinTypeError($function, $argIndex, $paramName, 'null', $expectedType)
                );
            }
            if (!self::requiresForwardProfileStrictStringNull()) {
                VmNullStringParamDeprecation::emit(null, $function, $argIndex, $paramName, $expectedType);
            }

            return '';
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::stringBuiltinTypeError($function, $argIndex, $paramName, 'array', $expectedType));
        }
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(
                self::stringBuiltinTypeError(
                    $function,
                    $argIndex,
                    $paramName,
                    EnumCaseSupport::typeNameForVariable($var),
                    $expectedType
                )
            );
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $vm = VM::running();
            $object = $var->toObject();
            if (null === $vm || !$vm->hasInstanceMethod($object->class, '__tostring')) {
                throw new \TypeError(
                    self::stringBuiltinTypeError($function, $argIndex, $paramName, $object->class->name, $expectedType)
                );
            }
        }

        return self::coerceOperand($var);
    }

    /**
     * Coerce a Z_PARAM_STR operand without object/__toString coercion (#10166, ext/standard/string.c).
     *
     * @throws \TypeError when the operand is array, object, enum case, or otherwise incompatible
     */
    public static function coerceStringBuiltinArgNoObject(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'string'
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            if (self::requiresForwardProfileStrictStringNull()) {
                throw new \TypeError(self::stringBuiltinTypeError($function, $argIndex, $paramName, 'null'));
            }
            VmNullStringParamDeprecation::emit(null, $function, $argIndex, $paramName);

            return '';
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::stringBuiltinTypeError($function, $argIndex, $paramName, 'array'));
        }
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(
                self::stringBuiltinTypeError(
                    $function,
                    $argIndex,
                    $paramName,
                    EnumCaseSupport::typeNameForVariable($var)
                )
            );
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(
                self::stringBuiltinTypeError(
                    $function,
                    $argIndex,
                    $paramName,
                    $var->toObject()->class->name
                )
            );
        }

        return self::coerceOperand($var);
    }

    /**
     * Require a string builtin operand (php-src Z_PARAM_STR; string type only, #5018).
     *
     * @throws \TypeError when the operand is not a string like Zend PHP 8.x
     */
    public static function requireStringBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'string'
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(
                self::stringBuiltinTypeError(
                    $function,
                    $argIndex,
                    $paramName,
                    VmStreamArg::debugTypeName($var)
                )
            );
        }

        return $var->toString();
    }

    /**
     * Z_PARAM_STR with caller strict_types parity (#12276 bindec/hexdec/octdec, #12274 base_convert).
     *
     * @throws \TypeError when caller strict_types rejects non-string operands
     */
    public static function stringBuiltinArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $paramName,
        bool $rejectNullOnForwardProfile = true
    ): string {
        if (InternalStrictArg::isCallerStrict($frame)) {
            // Use $userArgIndex for the TypeError message — method frames include $this at
            // index 0, so Zend cites Argument #1 for the first user param (#29819).
            $arg = $frame->calledArgs[$argIndex]->resolveIndirect();
            if (Variable::TYPE_STRING !== $arg->type) {
                throw new \TypeError(
                    self::stringBuiltinTypeError(
                        $function,
                        $userArgIndex,
                        $paramName,
                        VmStreamArg::debugTypeName($arg)
                    )
                );
            }

            return $arg->toString();
        }

        return self::coerceStringBuiltinArg(
            $frame->calledArgs[$argIndex],
            $function,
            $userArgIndex,
            $paramName,
            'string',
            $rejectNullOnForwardProfile
        );
    }

    /**
     * Z_PARAM_STR frame arg — null TypeError on 8.4 forward profile (#19297, #19276).
     *
     * @throws \TypeError when caller strict_types rejects non-string, or PROFILE=8.4 rejects null
     */
    public static function zparamStrBuiltinArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $paramName,
        string $expectedType = 'string'
    ): string {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, $argIndex, $function, $paramName);

            return $frame->calledArgs[$argIndex]->resolveIndirect()->toString();
        }

        return self::coerceZparamStrBuiltinArg(
            $frame->calledArgs[$argIndex],
            $function,
            $userArgIndex,
            $paramName,
            $expectedType
        );
    }

    /**
     * Internal method string param — frame arg index may include $this (#18189, zend_exceptions.c).
     *
     * @throws \TypeError when caller strict_types rejects non-string operands
     */
    public static function internalMethodStringArgForFrame(
        Frame $frame,
        int $frameArgIndex,
        string $function,
        int $userArgIndex,
        string $paramName
    ): string {
        if (InternalStrictArg::isCallerStrict($frame)) {
            $arg = $frame->calledArgs[$frameArgIndex]->resolveIndirect();
            if (Variable::TYPE_STRING !== $arg->type) {
                throw new \TypeError(
                    self::stringBuiltinTypeError(
                        $function,
                        $userArgIndex,
                        $paramName,
                        VmStreamArg::debugTypeName($arg)
                    )
                );
            }

            return $arg->toString();
        }

        return self::coerceStringBuiltinArg(
            $frame->calledArgs[$frameArgIndex],
            $function,
            $userArgIndex,
            $paramName
        );
    }

    /**
     * Coerce a path builtin operand (php-src Z_PARAM_PATH; rejects embedded NUL, #4401).
     *
     * Null soft-coerces with E_DEPRECATED through PHP 8.4 (php-src Z_PARAM_PATH / #20362).
     * $softNullPath is retained for call-site clarity; Z_PARAM_PATH is always soft-null.
     *
     * @throws \ValueError when the path contains a null byte
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coercePathBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'path',
        bool $softNullPath = true
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            VmNullStringParamDeprecation::emit(null, $function, $argIndex, $paramName);

            return '';
        }
        if (self::requiresTypedPathStringOnForwardProfile()) {
            if (Variable::TYPE_STRING === $var->type) {
                $str = $var->toString();
            } elseif (\in_array($var->type, [Variable::TYPE_INTEGER, Variable::TYPE_FLOAT, Variable::TYPE_BOOLEAN], true)) {
                throw new \TypeError(
                    self::stringBuiltinTypeError($function, $argIndex, $paramName, self::builtinScalarTypeName($var))
                );
            } else {
                $str = self::coerceStringBuiltinArg($var, $function, $argIndex, $paramName, 'string', false);
            }
        } else {
            $str = self::coerceStringBuiltinArg($var, $function, $argIndex, $paramName, 'string', false);
        }
        if (str_contains($str, "\0")) {
            throw new \ValueError(
                sprintf(
                    '%s(): Argument #%d ($%s) must not contain any null bytes',
                    $function,
                    $argIndex + 1,
                    $paramName
                )
            );
        }

        return $str;
    }

    /**
     * Reject embedded NUL in string builtin operands (php-src Z_PARAM_STR no null bytes; #12497).
     *
     * @throws \ValueError when the string contains a null byte
     */
    public static function rejectNullByteBuiltinStringArg(
        string $str,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        if (str_contains($str, "\0")) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($%s) must not contain any null bytes',
                $function,
                $argIndex + 1,
                $paramName
            ));
        }
    }

    /**
     * Zend empty-string ValueError wording (php-src zend_argument_error / string.c).
     *
     * Use {@see EMPTY_STRING_ARG_VALUE_ERROR_CANNOT} for builtins whose php-src path is
     * `zend_argument_must_not_be_empty_error` / current Zend text "cannot be empty"
     * (explode / substr_count — #30505/#30522; hash_hkdf, checkdnsrr, …).
     * Keep MUST_NOT only where Zend still prints that suffix (e.g. some mbstring paths).
     */
    public const EMPTY_STRING_ARG_VALUE_ERROR_MUST_NOT = 'must not be empty';

    /** php-src zend_argument_must_not_be_empty_error — current Zend text (#30505/#30522/#29760) */
    public const EMPTY_STRING_ARG_VALUE_ERROR_CANNOT = 'cannot be empty';

    /**
     * Format `fn(): Argument #N ($name) must not be empty` (1-based $argIndex).
     */
    public static function emptyStringArgValueErrorMessage(
        string $function,
        int $argIndex,
        string $paramName
    ): string {
        return self::emptyStringArgValueErrorMessageWithSuffix(
            $function,
            $argIndex,
            $paramName,
            self::EMPTY_STRING_ARG_VALUE_ERROR_MUST_NOT
        );
    }

    /**
     * Format `fn(): Argument #N ($name) cannot be empty` (1-based $argIndex).
     */
    public static function emptyStringArgValueErrorMessageCannot(
        string $function,
        int $argIndex,
        string $paramName
    ): string {
        return self::emptyStringArgValueErrorMessageWithSuffix(
            $function,
            $argIndex,
            $paramName,
            self::EMPTY_STRING_ARG_VALUE_ERROR_CANNOT
        );
    }

    private static function emptyStringArgValueErrorMessageWithSuffix(
        string $function,
        int $argIndex,
        string $paramName,
        string $suffix
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) %s',
            $function,
            $argIndex + 1,
            $paramName,
            $suffix
        );
    }

    /**
     * Reject empty string builtin operands (php-src Z_PARAM_STR non-empty path guards; #11031).
     *
     * @throws \ValueError when the coerced string is empty
     */
    public static function rejectEmptyBuiltinStringArg(
        string $str,
        string $function,
        int $argIndex,
        string $paramName,
        bool $cannotWording = false
    ): void {
        if ('' === $str) {
            throw new \ValueError(
                $cannotWording
                    ? self::emptyStringArgValueErrorMessageCannot($function, $argIndex, $paramName)
                    : self::emptyStringArgValueErrorMessage($function, $argIndex, $paramName)
            );
        }
    }

    /**
     * Coerce disk_*_space() directory operand (php-src filestat.c).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coerceOptionalDirectoryArg(Variable $var, string $function): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return self::coerceStringBuiltinArg($var, $function, 0, 'directory');
    }

    /**
     * Coerce a ?string builtin operand (php-src Z_PARAM_STR with null; #6536 session_name).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coerceNullableStringBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'string'
    ): ?string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::nullableStringBuiltinTypeError($function, $argIndex, $paramName, 'array'));
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(
                self::nullableStringBuiltinTypeError(
                    $function,
                    $argIndex,
                    $paramName,
                    EnumCaseSupport::typeNameForVariable($var)
                )
            );
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $vm = VM::running();
            $object = $var->toObject();
            if (null === $vm || !$vm->hasInstanceMethod($object->class, '__tostring')) {
                throw new \TypeError(
                    self::nullableStringBuiltinTypeError($function, $argIndex, $paramName, $object->class->name)
                );
            }
        }

        return self::coerceOperand($var);
    }

    /**
     * Coerce a typed ?string builtin operand (php-src Z_PARAM_STR_OR_NULL; string|null only, #17765).
     *
     * @throws \TypeError when the operand is not string or null
     */
    public static function coerceTypedNullableStringBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'string'
    ): ?string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::nullableStringBuiltinTypeError($function, $argIndex, $paramName, 'array'));
        }
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(
                self::nullableStringBuiltinTypeError(
                    $function,
                    $argIndex,
                    $paramName,
                    EnumCaseSupport::typeNameForVariable($var)
                )
            );
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(
                self::nullableStringBuiltinTypeError(
                    $function,
                    $argIndex,
                    $paramName,
                    $var->toObject()->class->name
                )
            );
        }

        throw new \TypeError(
            self::nullableStringBuiltinTypeError(
                $function,
                $argIndex,
                $paramName,
                self::builtinScalarTypeName($var)
            )
        );
    }

    /**
     * Z_PARAM_STR_OR_NULL with caller strict_types parity (#18870, ext/standard/ini.c).
     *
     * @throws \TypeError when caller strict_types rejects non-string operands
     */
    public static function typedNullableStringBuiltinArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $paramName
    ): ?string {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return self::coerceTypedNullableStringBuiltinArg(
                $frame->calledArgs[$argIndex],
                $function,
                $userArgIndex,
                $paramName
            );
        }

        return self::coerceNullableStringBuiltinArg(
            $frame->calledArgs[$argIndex],
            $function,
            $userArgIndex,
            $paramName
        );
    }

    private static function builtinScalarTypeName(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }

    /**
     * strtok() arg #1 ($string) — accepts null; invalid types report "string" not "?string" (#9207, php-src string.c).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coerceStrtokStringArg(Variable $var): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            if (self::requiresZparamStrStrictNullOnForwardProfile()) {
                VmNullStringParamDeprecation::emit(null, 'strtok', 0, 'string');

                return '';
            }

            return null;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::stringBuiltinTypeError('strtok', 0, 'string', 'array'));
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(
                self::stringBuiltinTypeError(
                    'strtok',
                    0,
                    'string',
                    EnumCaseSupport::typeNameForVariable($var)
                )
            );
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $vm = VM::running();
            $object = $var->toObject();
            if (null === $vm || !$vm->hasInstanceMethod($object->class, '__tostring')) {
                throw new \TypeError(
                    self::stringBuiltinTypeError('strtok', 0, 'string', $object->class->name)
                );
            }
        }

        return self::coerceOperand($var);
    }

    /**
     * strtok() arg #2 ($token) — Z_PARAM_STR_OR_NULL; null stays null (#25171, #9207, php-src string.c).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coerceStrtokTokenArg(Variable $var): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            // php-src Z_PARAM_STR_OR_NULL — null token selects one-arg mode (tok = str), not "".
            return null;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::nullableStringBuiltinTypeError('strtok', 1, 'token', 'array'));
        }
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(
                self::nullableStringBuiltinTypeError(
                    'strtok',
                    1,
                    'token',
                    EnumCaseSupport::typeNameForVariable($var)
                )
            );
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $vm = VM::running();
            $object = $var->toObject();
            if (null === $vm || !$vm->hasInstanceMethod($object->class, '__tostring')) {
                throw new \TypeError(
                    self::nullableStringBuiltinTypeError('strtok', 1, 'token', $object->class->name)
                );
            }
        }

        return self::coerceOperand($var);
    }

    private static function stringBuiltinTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given,
        string $expectedType = 'string'
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $expectedType,
            $given
        );
    }

    private static function nullableStringBuiltinTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type ?string, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $given
        );
    }

    /** Regex metacharacters escaped by preg_quote() (PHP 8.2 byte subset). */
    private const PREG_QUOTE_ESCAPE = '.\\+*?[^]()$={}-|!<>:';

    /** Metacharacters escaped by quotemeta() (PHP 8.2 byte subset). */
    private const QUOTEMETA_ESCAPE = '.\\+*?[]^()$';

    public static function byteLength(string $string): int
    {
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }

        return $len;
    }

    /**
     * UTF-8 codepoint count for BMP web text (issue #158). Invalid bytes count as one character.
     */
    public static function utf8CharLength(string $string): int
    {
        $byteLen = self::byteLength($string);
        $count = 0;
        for ($i = 0; $i < $byteLen; ++$count) {
            $byte = \ord($string[$i]);
            if ($byte < 0x80) {
                $i += 1;
            } elseif (($byte & 0xE0) === 0xC0 && $i + 1 < $byteLen) {
                $i += 2;
            } elseif (($byte & 0xF0) === 0xE0 && $i + 2 < $byteLen) {
                $i += 3;
            } elseif (($byte & 0xF8) === 0xF0 && $i + 3 < $byteLen) {
                $i += 4;
            } else {
                $i += 1;
            }
        }

        return $count;
    }

    /** Byte width of one UTF-8 codepoint at $bytePos (invalid sequences count as one byte). */
    public static function utf8CharByteWidth(string $string, int $bytePos): int
    {
        $byteLen = self::byteLength($string);
        if ($bytePos >= $byteLen) {
            return 0;
        }
        $byte = \ord($string[$bytePos]);
        if ($byte < 0x80) {
            return 1;
        }
        if (($byte & 0xE0) === 0xC0 && $bytePos + 1 < $byteLen) {
            return 2;
        }
        if (($byte & 0xF0) === 0xE0 && $bytePos + 2 < $byteLen) {
            return 3;
        }
        if (($byte & 0xF8) === 0xF0 && $bytePos + 3 < $byteLen) {
            return 4;
        }

        return 1;
    }

    /** Whether $string is well-formed UTF-8 (php-src ext/mbstring; mb_check_encoding UTF-8 path). */
    public static function isValidUtf8(string $string): bool
    {
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ) {
            if (!self::utf8SequenceValidAt($string, $len, $i, $need)) {
                return false;
            }
            $i += $need + 1;
        }

        return true;
    }

    /** Replace invalid UTF-8 byte sequences with U+FFFD (php-src ext/standard/html.c ENT_SUBSTITUTE). */
    private static function utf8SubstituteInvalidSequences(string $string): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ) {
            if (self::utf8SequenceValidAt($string, $len, $i, $need)) {
                $out .= \substr($string, $i, $need + 1);
                $i += $need + 1;
            } else {
                $out .= "\xEF\xBF\xBD";
                ++$i;
            }
        }

        return $out;
    }

    /**
     * @param-out int $need continuation byte count when lead byte is multi-byte
     */
    private static function utf8SequenceValidAt(string $string, int $len, int $i, ?int &$need = null): bool
    {
        $byte = \ord($string[$i]);
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
            $next = \ord($string[$i + $j]);
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
     * UTF-8 substring measured in codepoints (php-src ext/mbstring mb_get_substr; #7044).
     */
    public static function utf8CharSubstr(string $string, int $charOffset, int $charCount): string
    {
        if ($charCount <= 0) {
            return '';
        }
        $byteLen = self::byteLength($string);
        $bytePos = 0;
        for ($skipped = 0; $skipped < $charOffset && $bytePos < $byteLen; ++$skipped) {
            $bytePos += self::utf8CharByteWidth($string, $bytePos);
        }
        $start = $bytePos;
        for ($taken = 0; $taken < $charCount && $bytePos < $byteLen; ++$taken) {
            $bytePos += self::utf8CharByteWidth($string, $bytePos);
        }

        return self::byteSlice($string, $start, $bytePos - $start);
    }

    public static function byteSlice(
        string $string,
        int $offset,
        ?int $length = null,
        bool $warnOnClip = false,
        ?\PHPCompiler\Frame $frame = null,
        string $function = 'substr',
    ): string {
        $len = self::byteLength($string);
        if ($offset < 0) {
            $offset = $len + $offset;
            if ($offset < 0) {
                $offset = 0;
            }
        }
        // offset >= len: empty remainder — Zend-silent (no Z_STR_TRUNCATED); #22489
        if ($offset >= $len) {
            return '';
        }
        if (null === $length) {
            $length = $len - $offset;
        } elseif ($length < 0) {
            $length = $len - $offset + $length;
            if ($length < 0) {
                return '';
            }
        }
        // Oversize positive length clamps silently in php-src php_substr — including PHP_INT_MAX
        // (#28556). $warnOnClip is retained for mb_substr callers that still opt in.
        if ($warnOnClip && $length > 0 && $offset + $length > $len) {
            self::emitSubstrTruncatedWarning($frame, $function);
        }
        if ($offset + $length > $len) {
            $length = $len - $offset;
        }
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $string[$offset + $i];
        }

        return $out;
    }

    public static function strrev(string $string): string
    {
        $len = self::byteLength($string);
        $out = '';
        for ($i = $len - 1; $i >= 0; --$i) {
            $out .= $string[$i];
        }

        return $out;
    }

    /**
     * str_shuffle() — Fisher–Yates on bytes (CSPRNG via {@see randomBytes()}).
     */
    public static function strShuffle(string $string): string
    {
        $len = self::byteLength($string);
        if ($len < 2) {
            return $string;
        }
        $chars = [];
        for ($i = 0; $i < $len; ++$i) {
            $chars[$i] = $string[$i];
        }
        for ($i = $len - 1; $i > 0; --$i) {
            $rand = self::randomBytes(8);
            $pick = 0;
            for ($b = 0; $b < 8; ++$b) {
                $pick = ($pick << 8) | self::byteOrd($rand[$b]);
            }
            $j = $pick % ($i + 1);
            if ($j < 0) {
                $j += $i + 1;
            }
            $tmp = $chars[$i];
            $chars[$i] = $chars[$j];
            $chars[$j] = $tmp;
        }

        return \implode('', $chars);
    }

    /** chunk_split() — chunk string and append separator after each chunk (PHP semantics). */
    public static function chunkSplit(string $string, int $length, string $separator = "\r\n"): string
    {
        if ($length < 1) {
            throw new \ValueError('chunk_split(): Argument #2 ($length) must be greater than 0');
        }
        $byteLen = self::byteLength($string);
        if (0 === $byteLen) {
            return $separator;
        }
        $out = '';
        for ($i = 0; $i < $byteLen; $i += $length) {
            $out .= self::byteSlice($string, $i, $length);
            $out .= $separator;
        }

        return $out;
    }

    /**
     * wordwrap() — wrap string to width at spaces (PHP semantics; byte-oriented subset).
     *
     * SSOT: {@see VmWordwrap::wrap} (NestedJIT-safe strlen/substr; #30812).
     */
    public static function wordwrap(string $text, int $width = 75, string $break = "\n", bool $cut = false): string
    {
        return VmWordwrap::wrap($text, $width, $break, $cut ? 1 : 0);
    }

    private static function byteReplaceAt(string $string, int $offset, string $byte): string
    {
        if ($offset < 0 || $offset >= self::byteLength($string)) {
            return $string;
        }
        $prefix = 0 === $offset ? '' : self::byteSlice($string, 0, $offset);
        $suffix = self::byteSlice($string, $offset + 1);

        return $prefix . $byte . $suffix;
    }

    private static function byteCompareN(string $a, int $aOff, string $b, int $bOff, int $n): int
    {
        for ($i = 0; $i < $n; ++$i) {
            if ($a[$aOff + $i] !== $b[$bOff + $i]) {
                return 1;
            }
        }

        return 0;
    }

    public static function strcmp(string $a, string $b): int
    {
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $min = $lenA < $lenB ? $lenA : $lenB;
        for ($i = 0; $i < $min; ++$i) {
            $ordA = self::byteOrd($a[$i]);
            $ordB = self::byteOrd($b[$i]);
            if ($ordA !== $ordB) {
                return $ordA - $ordB;
            }
        }

        return $lenA <=> $lenB;
    }

    /**
     * strnatcmp() — byte-oriented natural order (subset of PHP; issue #2358).
     */
    public static function strnatcmp(string $a, string $b): int
    {
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $ia = 0;
        $ib = 0;
        while ($ia < $lenA && $ib < $lenB) {
            $ordA = self::byteOrd($a[$ia]);
            $ordB = self::byteOrd($b[$ib]);
            $digA = $ordA >= 48 && $ordA <= 57;
            $digB = $ordB >= 48 && $ordB <= 57;
            if ($digA && $digB) {
                while ($ia < $lenA && 48 === self::byteOrd($a[$ia])) {
                    ++$ia;
                }
                while ($ib < $lenB && 48 === self::byteOrd($b[$ib])) {
                    ++$ib;
                }
                $startA = $ia;
                $startB = $ib;
                while ($ia < $lenA) {
                    $o = self::byteOrd($a[$ia]);
                    if ($o < 48 || $o > 57) {
                        break;
                    }
                    ++$ia;
                }
                while ($ib < $lenB) {
                    $o = self::byteOrd($b[$ib]);
                    if ($o < 48 || $o > 57) {
                        break;
                    }
                    ++$ib;
                }
                $numLenA = $ia - $startA;
                $numLenB = $ib - $startB;
                if (0 === $numLenA && 0 === $numLenB) {
                    continue;
                }
                if ($numLenA !== $numLenB) {
                    return $numLenA <=> $numLenB;
                }
                for ($k = 0; $k < $numLenA; ++$k) {
                    $da = self::byteOrd($a[$startA + $k]);
                    $db = self::byteOrd($b[$startB + $k]);
                    if ($da !== $db) {
                        return $da <=> $db;
                    }
                }

                continue;
            }
            if ($ordA !== $ordB) {
                return $ordA <=> $ordB;
            }
            ++$ia;
            ++$ib;
        }

        return ($lenA - $ia) <=> ($lenB - $ib);
    }

    /**
     * strnatcasecmp() — byte-oriented natural order, ASCII case-insensitive (#2372).
     */
    public static function strnatcasecmp(string $a, string $b): int
    {
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $ia = 0;
        $ib = 0;
        while ($ia < $lenA && $ib < $lenB) {
            $ordA = self::byteOrd($a[$ia]);
            $ordB = self::byteOrd($b[$ib]);
            $digA = $ordA >= 48 && $ordA <= 57;
            $digB = $ordB >= 48 && $ordB <= 57;
            if ($digA && $digB) {
                while ($ia < $lenA && 48 === self::byteOrd($a[$ia])) {
                    ++$ia;
                }
                while ($ib < $lenB && 48 === self::byteOrd($b[$ib])) {
                    ++$ib;
                }
                $startA = $ia;
                $startB = $ib;
                while ($ia < $lenA) {
                    $o = self::byteOrd($a[$ia]);
                    if ($o < 48 || $o > 57) {
                        break;
                    }
                    ++$ia;
                }
                while ($ib < $lenB) {
                    $o = self::byteOrd($b[$ib]);
                    if ($o < 48 || $o > 57) {
                        break;
                    }
                    ++$ib;
                }
                $numLenA = $ia - $startA;
                $numLenB = $ib - $startB;
                if (0 === $numLenA && 0 === $numLenB) {
                    continue;
                }
                if ($numLenA !== $numLenB) {
                    return $numLenA <=> $numLenB;
                }
                for ($k = 0; $k < $numLenA; ++$k) {
                    $da = self::byteOrd($a[$startA + $k]);
                    $db = self::byteOrd($b[$startB + $k]);
                    if ($da !== $db) {
                        return $da <=> $db;
                    }
                }

                continue;
            }
            $ordA = self::byteOrd(self::asciiLowerByte($a[$ia]));
            $ordB = self::byteOrd(self::asciiLowerByte($b[$ib]));
            if ($ordA !== $ordB) {
                return $ordA <=> $ordB;
            }
            ++$ia;
            ++$ib;
        }

        return ($lenA - $ia) <=> ($lenB - $ib);
    }

    public static function strncmp(string $a, string $b, int $length): int
    {
        if ($length <= 0) {
            return 0;
        }
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        for ($i = 0; $i < $length; ++$i) {
            if ($i >= $lenA) {
                if ($i >= $lenB) {
                    return 0;
                }
                if (0 === $lenA) {
                    return -1;
                }
                $ordA = 0;
                $ordB = self::byteOrd($b[$i]);
            } elseif ($i >= $lenB) {
                if (0 === $lenB) {
                    return 1;
                }
                $ordA = self::byteOrd($a[$i]);
                $ordB = 0;
            } else {
                $ordA = self::byteOrd($a[$i]);
                $ordB = self::byteOrd($b[$i]);
            }
            if ($ordA !== $ordB) {
                return $ordA - $ordB;
            }
        }

        return 0;
    }

    /**
     * Internal length-limited binary compare (zend_binary_strcmp; #7118 / #25359).
     * Not a userland builtin — php-src string.stub.php has no memcmp().
     */
    public static function memcmp(string $a, string $b, int $length): int
    {
        if ($length <= 0) {
            return 0;
        }
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $compare = $length;
        if ($compare > $lenA) {
            $compare = $lenA;
        }
        if ($compare > $lenB) {
            $compare = $lenB;
        }
        for ($i = 0; $i < $compare; ++$i) {
            $ordA = self::byteOrd($a[$i]);
            $ordB = self::byteOrd($b[$i]);
            if ($ordA !== $ordB) {
                return $ordA - $ordB;
            }
        }

        return $lenA <=> $lenB;
    }

    public static function strcasecmp(string $a, string $b): int
    {
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        $min = $lenA < $lenB ? $lenA : $lenB;
        for ($i = 0; $i < $min; ++$i) {
            $ordA = self::byteOrd(self::asciiLowerByte($a[$i]));
            $ordB = self::byteOrd(self::asciiLowerByte($b[$i]));
            if ($ordA !== $ordB) {
                return $ordA - $ordB;
            }
        }

        return $lenA <=> $lenB;
    }

    public static function strncasecmp(string $a, string $b, int $length): int
    {
        if ($length <= 0) {
            return 0;
        }
        $lenA = self::byteLength($a);
        $lenB = self::byteLength($b);
        for ($i = 0; $i < $length; ++$i) {
            if ($i >= $lenA || $i >= $lenB) {
                return $lenA <=> $lenB;
            }
            $ordA = self::byteOrd(self::asciiLowerByte($a[$i]));
            $ordB = self::byteOrd(self::asciiLowerByte($b[$i]));
            if ($ordA !== $ordB) {
                return $ordA - $ordB;
            }
        }

        return 0;
    }

    /**
     * substr_compare() — byte-oriented haystack slice vs needle (subset of PHP; issue #2400).
     */
    public static function substr_compare(
        string $haystack,
        string $needle,
        int $offset,
        ?int $length = null,
        bool $caseInsensitive = false
    ): int {
        $hayLen = self::byteLength($haystack);
        if ($offset < 0) {
            $offset += $hayLen;
            if ($offset < 0) {
                $offset = 0;
            }
        }
        if ($offset > $hayLen) {
            throw new \ValueError('substr_compare(): Argument #3 ($offset) must be contained in argument #1 ($haystack)');
        }
        $needleLen = self::byteLength($needle);
        $hayRemain = $hayLen - $offset;
        $compareLen = $hayRemain;
        $lengthOmitted = null === $length;
        if (!$lengthOmitted) {
            if ($length < 0) {
                throw new \ValueError('substr_compare(): Argument #4 ($length) must be greater than or equal to 0');
            }
            if ($length > $hayRemain) {
                $length = $hayRemain;
            }
            $compareLen = $length;
        } else {
            $length = $needleLen > $hayRemain ? $hayRemain : $needleLen;
        }
        $s1 = self::byteSlice($haystack, $offset, $length);
        $strncmpLen = $lengthOmitted ? $length : min($length, $needleLen);
        $cmp = $caseInsensitive
            ? self::strncmpCase($s1, $needle, $strncmpLen)
            : self::strncmp($s1, $needle, $strncmpLen);
        if (0 !== $cmp) {
            return $cmp;
        }
        if ($compareLen !== $needleLen) {
            if ($lengthOmitted) {
                return $compareLen < $needleLen ? -1 : 1;
            }
            if ($compareLen > $needleLen) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * levenshtein() — byte-oriented edit distance (subset of PHP; issue #2406).
     *
     * SSOT: {@see VmLevenshtein::compute} (NestedJIT-safe digit-string DP; #26830 / #30790).
     */
    public static function levenshtein(
        string $string1,
        string $string2,
        int $insertionCost = 1,
        int $replacementCost = 1,
        int $deletionCost = 1
    ): int {
        return VmLevenshtein::compute(
            $string1,
            $string2,
            $insertionCost,
            $replacementCost,
            $deletionCost
        );
    }

    /**
     * similar_text() — PHP-compatible Oliver algorithm (issue #2445).
     */
    public static function similar_text(string $string1, string $string2, ?float &$percent = null): int
    {
        $len1 = self::byteLength($string1);
        $len2 = self::byteLength($string2);
        if (0 === $len1 && 0 === $len2) {
            if (null !== $percent) {
                $percent = 0.0;
            }

            return 0;
        }
        $sim = self::similarChar($string1, $len1, $string2, $len2);
        if (null !== $percent) {
            $percent = $sim * 200.0 / ($len1 + $len2);
        }

        return $sim;
    }

    private static function similarStr(
        string $txt1,
        int $len1,
        string $txt2,
        int $len2,
        int &$pos1,
        int &$pos2,
        int &$max,
        int &$count
    ): void {
        $max = 0;
        $count = 0;
        for ($p = 0; $p < $len1; ++$p) {
            for ($q = 0; $q < $len2; ++$q) {
                $l = 0;
                while (
                    $p + $l < $len1
                    && $q + $l < $len2
                    && $txt1[$p + $l] === $txt2[$q + $l]
                ) {
                    ++$l;
                }
                if ($l > $max) {
                    $max = $l;
                    ++$count;
                    $pos1 = $p;
                    $pos2 = $q;
                }
            }
        }
    }

    private static function similarChar(string $txt1, int $len1, string $txt2, int $len2): int
    {
        $pos1 = 0;
        $pos2 = 0;
        $max = 0;
        $count = 0;
        self::similarStr($txt1, $len1, $txt2, $len2, $pos1, $pos2, $max, $count);
        $sum = $max;
        if ($sum > 0) {
            if ($pos1 > 0 && $pos2 > 0 && $count > 1) {
                $sum += self::similarChar(
                    substr($txt1, 0, $pos1),
                    $pos1,
                    substr($txt2, 0, $pos2),
                    $pos2
                );
            }
            if ($pos1 + $max < $len1 && $pos2 + $max < $len2) {
                $sum += self::similarChar(
                    substr($txt1, $pos1 + $max),
                    $len1 - $pos1 - $max,
                    substr($txt2, $pos2 + $max),
                    $len2 - $pos2 - $max
                );
            }
        }

        return $sum;
    }

    /**
     * metaphone() — PHP-compatible Metaphone on ASCII letters (issue #2423).
     */
    public static function metaphone(string $string, int $maxPhonemes = 0): string
    {
        return VmMetaphone::encode($string, $maxPhonemes);
    }

    /**
     * soundex() — PHP-compatible Soundex on ASCII letters (issue #2416).
     *
     * SSOT: {@see VmSoundex::encode} (NestedJIT-safe recursive substr; #30790).
     */
    public static function soundex(string $string): string
    {
        return VmSoundex::encode($string);
    }

    private static function strncmpCase(string $a, string $b, int $length): int
    {
        return self::strncasecmp($a, $b, $length);
    }

    /**
     * @return array{0: int, 1: int} start offset and segment length (php_spn_common_handler)
     */
    private static function normalizeSpnBounds(int $strLen, int $start, ?int $length): array
    {
        $remainLen = $strLen;
        if ($start < 0) {
            $start += $remainLen;
            if ($start < 0) {
                $start = 0;
            }
        } elseif ($start > $remainLen) {
            $start = $remainLen;
        }
        $remainLen -= $start;
        if (null === $length) {
            $length = $remainLen;
        } elseif ($length < 0) {
            $length += $remainLen;
            if ($length < 0) {
                $length = 0;
            }
        } elseif ($length > $remainLen) {
            $length = $remainLen;
        }

        return [$start, $length];
    }

    /**
     * PHP 8.4 (GH-12592): empty $mask returns 0; strcspn() returns full segment byte length.
     */
    public static function strspn(string $str, string $mask, int $offset = 0, ?int $length = null): int
    {
        $slen = self::byteLength($str);
        [$start, $len] = self::normalizeSpnBounds($slen, $offset, $length);
        if ('' === $mask || 0 === $len) {
            return 0;
        }
        $mlen = self::byteLength($mask);
        $count = 0;
        for ($i = $start; $i < $start + $len; ++$i) {
            if (!self::byteInSet($str[$i], $mask, $mlen)) {
                break;
            }
            ++$count;
        }

        return $count;
    }

    /**
     * PHP 8.4 (GH-12592): empty $mask returns full segment byte length (not NUL-terminated walk).
     * PROFILE&lt;8.4: empty $mask stops at the first embedded NUL like classic php-src / C strcspn (#27716).
     */
    public static function strcspn(string $str, string $mask, int $offset = 0, ?int $length = null): int
    {
        $slen = self::byteLength($str);
        [$start, $len] = self::normalizeSpnBounds($slen, $offset, $length);
        if (0 === $len) {
            return 0;
        }
        if ('' === $mask) {
            if (!self::strcspnEmptyMaskStopsAtNul()) {
                return $len;
            }
            // PROFILE≤8.3 empty mask — stop at embedded NUL within the segment (#27716).
            $count = 0;
            for ($i = $start; $i < $start + $len; ++$i) {
                if ("\0" === $str[$i]) {
                    break;
                }
                ++$count;
            }

            return $count;
        }
        $mlen = self::byteLength($mask);
        $count = 0;
        for ($i = $start; $i < $start + $len; ++$i) {
            if (self::byteInSet($str[$i], $mask, $mlen)) {
                break;
            }
            ++$count;
        }

        return $count;
    }

    /**
     * Empty $characters + embedded NUL (#27716 / GH-12592).
     *
     * Explicit PROFILE&lt;8.4 matches Zend 8.2 (stop at NUL). Unset PROFILE and PROFILE≥8.4 keep
     * the binary-safe full-segment length from #7088 (`strcspn_empty_characters_84`).
     */
    public static function strcspnEmptyMaskStopsAtNul(): bool
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '<');
    }

    public static function strpbrk(string $str, string $mask) {
        if ('' === $mask) {
            throw new \ValueError('strpbrk(): Argument #2 ($characters) must be a non-empty string');
        }
        $slen = self::byteLength($str);
        $mlen = self::byteLength($mask);
        for ($i = 0; $i < $slen; ++$i) {
            if (self::byteInSet($str[$i], $mask, $mlen)) {
                return self::byteSlice($str, $i);
            }
        }

        return false;
    }

    private static function byteInSet(string $byte, string $mask, int $maskLen): bool
    {
        for ($j = 0; $j < $maskLen; ++$j) {
            if ($byte === $mask[$j]) {
                return true;
            }
        }

        return false;
    }

    public static function bin2hex(string $data): string
    {
        $hex = '0123456789abcdef';
        $len = self::byteLength($data);
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $ord = self::byteOrd($data[$i]);
            $out .= $hex[$ord >> 4];
            $out .= $hex[$ord & 0x0F];
        }

        return $out;
    }

    /**
     * Decode a hex string to binary (PHP hex2bin subset).
     *
     * @return string|false decoded bytes, or false when input is invalid (non-strict)
     *
     * @throws \Error when $strict is true and input has odd length or invalid hex
     */
    public static function hex2bin(string $data, bool $strict = false)
    {
        $len = self::byteLength($data);
        if (0 === $len) {
            return '';
        }
        if (0 !== ($len & 1)) {
            if ($strict) {
                throw new \Error('Hexadecimal input string must have an even length');
            }

            return false;
        }
        $out = '';
        for ($i = 0; $i < $len; $i += 2) {
            $hi = self::hexDigit(self::byteOrd($data[$i]));
            $lo = self::hexDigit(self::byteOrd($data[$i + 1]));
            if (null === $hi || null === $lo) {
                if ($strict) {
                    throw new \Error('Input string must be hexadecimal string');
                }

                return false;
            }
            $out .= \chr(($hi << 4) | $lo);
        }

        return $out;
    }

    private const BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** RFC 4648 base64 encode (standard alphabet, padding). */
    public static function base64_encode(string $data): string
    {
        $len = self::byteLength($data);
        if (0 === $len) {
            return '';
        }
        $alphabet = self::BASE64_ALPHABET;
        $out = '';
        for ($i = 0; $i < $len; $i += 3) {
            $b0 = self::byteOrd($data[$i]);
            $b1 = ($i + 1 < $len) ? self::byteOrd($data[$i + 1]) : 0;
            $b2 = ($i + 2 < $len) ? self::byteOrd($data[$i + 2]) : 0;
            $n = ($b0 << 16) | ($b1 << 8) | $b2;
            $out .= $alphabet[($n >> 18) & 63];
            $out .= $alphabet[($n >> 12) & 63];
            if ($i + 1 < $len) {
                $out .= $alphabet[($n >> 6) & 63];
            } else {
                $out .= '=';
            }
            if ($i + 2 < $len) {
                $out .= $alphabet[$n & 63];
            } else {
                $out .= '=';
            }
        }

        return $out;
    }

    /**
     * php-src ext/standard/base64.c base64_reverse_table: -2 invalid, -1 whitespace, 0..63 digit.
     */
    private static function base64ReverseChar(int $ch): int
    {
        static $table = null;
        if (null === $table) {
            $table = array_fill(0, 256, -2);
            foreach ([9, 10, 13, 32] as $ws) {
                $table[$ws] = -1;
            }
            $alphabet = self::BASE64_ALPHABET;
            for ($i = 0; $i < 64; ++$i) {
                $table[self::byteOrd($alphabet[$i])] = $i;
            }
        }

        return $table[$ch] ?? -2;
    }

    /**
     * RFC 4648 base64 decode (php-src php_base64_decode_impl).
     *
     * Non-strict: ignore bytes outside the alphabet (whitespace skipped).
     * Strict: reject invalid characters, bad padding, and truncated groups.
     *
     * @return string|false decoded bytes, or false when input is invalid
     */
    public static function base64_decode(string $data, bool $strict = false)
    {
        $len = self::byteLength($data);
        if (0 === $len) {
            return '';
        }
        $out = '';
        $j = 0;
        $i = 0;
        $padding = 0;
        for ($pos = 0; $pos < $len; ++$pos) {
            $ch = self::byteOrd($data[$pos]);
            if (61 === $ch) {
                ++$padding;
                continue;
            }
            $d = self::base64ReverseChar($ch);
            if (!$strict) {
                if ($d < 0) {
                    continue;
                }
            } else {
                if (-1 === $d) {
                    continue;
                }
                if (-2 === $d || $padding > 0) {
                    return false;
                }
            }
            switch ($i % 4) {
                case 0:
                    $out .= \chr($d << 2);
                    break;
                case 1:
                    $out[$j] = \chr((self::byteOrd($out[$j]) | ($d >> 4)) & 0xFF);
                    ++$j;
                    $out .= \chr(($d & 0x0f) << 4);
                    break;
                case 2:
                    $out[$j] = \chr((self::byteOrd($out[$j]) | ($d >> 2)) & 0xFF);
                    ++$j;
                    $out .= \chr(($d & 0x03) << 6);
                    break;
                case 3:
                    $out[$j] = \chr((self::byteOrd($out[$j]) | $d) & 0xFF);
                    ++$j;
                    break;
            }
            ++$i;
        }
        if ($strict && 1 === $i % 4) {
            return false;
        }
        if ($strict && $padding > 0 && ($padding > 2 || 0 !== ($i + $padding) % 4)) {
            return false;
        }

        return \substr($out, 0, $j);
    }

    private const QPRINT_MAXL = 75;

    /** quoted_printable_encode() — php-src ext/standard/quot_print.c. */
    public static function quoted_printable_encode(string $str): string
    {
        $length = self::byteLength($str);
        if (0 === $length) {
            return '';
        }
        $hex = '0123456789ABCDEF';
        $out = '';
        $lp = 0;
        for ($i = 0; $i < $length; ++$i) {
            $c = self::byteOrd($str[$i]);
            if (13 === $c && $i + 1 < $length && 10 === self::byteOrd($str[$i + 1])) {
                $out .= "\r\n";
                ++$i;
                $lp = 0;

                continue;
            }
            $nextIsCr = ($i + 1 < $length) && 13 === self::byteOrd($str[$i + 1]);
            if (
                $c < 32 || 127 === $c || 0 !== ($c & 0x80) || 61 === $c
                || (32 === $c && $nextIsCr)
            ) {
                if (
                    (($lp += 3) > self::QPRINT_MAXL && $c <= 0x7f)
                    || ($c > 0x7f && $c <= 0xdf && ($lp + 3) > self::QPRINT_MAXL)
                    || ($c > 0xdf && $c <= 0xef && ($lp + 6) > self::QPRINT_MAXL)
                    || ($c > 0xef && $c <= 0xf4 && ($lp + 9) > self::QPRINT_MAXL)
                ) {
                    $out .= "=\r\n";
                    $lp = 3;
                }
                $out .= '='.$hex[$c >> 4].$hex[$c & 0xf];
            } else {
                if ((++$lp) > self::QPRINT_MAXL) {
                    $out .= "=\r\n";
                    $lp = 1;
                }
                $out .= $str[$i];
            }
        }

        return $out;
    }

    /** quoted_printable_decode() — php-src ext/standard/quot_print.c PHP_FUNCTION. */
    public static function quoted_printable_decode(string $str): string
    {
        $inLen = self::byteLength($str);
        if (0 === $inLen) {
            return '';
        }
        $out = '';
        $i = 0;
        while ($i < $inLen) {
            $ch = self::byteOrd($str[$i]);
            if (61 === $ch) {
                if (
                    $i + 2 < $inLen
                    && self::isHexDigit($str[$i + 1])
                    && self::isHexDigit($str[$i + 2])
                ) {
                    $out .= \chr((self::hexDigitVal(self::byteOrd($str[$i + 1])) << 4)
                        + self::hexDigitVal(self::byteOrd($str[$i + 2])));
                    $i += 3;

                    continue;
                }
                $k = 1;
                while ($i + $k < $inLen) {
                    $sk = self::byteOrd($str[$i + $k]);
                    if (32 !== $sk && 9 !== $sk) {
                        break;
                    }
                    ++$k;
                }
                if ($i + $k >= $inLen) {
                    $i += $k;

                    continue;
                }
                if (
                    $i + $k + 1 < $inLen
                    && 13 === self::byteOrd($str[$i + $k])
                    && 10 === self::byteOrd($str[$i + $k + 1])
                ) {
                    $i += $k + 2;

                    continue;
                }
                if ($i + $k < $inLen) {
                    $sk = self::byteOrd($str[$i + $k]);
                    if (13 === $sk || 10 === $sk) {
                        $i += $k + 1;

                        continue;
                    }
                }
                $out .= $str[$i];
                ++$i;
            } else {
                $out .= $str[$i];
                ++$i;
            }
        }

        return $out;
    }

    private static function hexDigitVal(int $c): int
    {
        if ($c >= 48 && $c <= 57) {
            return $c - 48;
        }
        if ($c >= 65 && $c <= 70) {
            return $c - 65 + 10;
        }

        return $c - 97 + 10;
    }

    /**
     * Unix-to-Unix encode (php-src ext/standard/uuencode.c — php_uuencode).
     *
     * SSOT: {@see VmConvertUu::encode} (NestedJIT-safe strlen/substr; #30811).
     */
    public static function convert_uuencode(string $src): string
    {
        return VmConvertUu::encode($src);
    }

    /**
     * Unix-to-Unix decode (php-src ext/standard/uuencode.c — php_uudecode).
     *
     * SSOT: {@see VmConvertUu::decode} (NestedJIT-safe strlen/substr; #30811).
     *
     * @return string|false
     */
    public static function convert_uudecode(string $src)
    {
        return VmConvertUu::decode($src);
    }

    /** ISO-8859-1 to UTF-8 (php-src ext/standard/basic_functions.c — PHP_FUNCTION(utf8_encode)). */
    public static function utf8_encode(string $data): string
    {
        $srcLen = self::byteLength($data);
        if (0 === $srcLen) {
            return '';
        }
        $out = '';
        for ($i = 0; $i < $srcLen; ++$i) {
            $c = self::byteOrd($data[$i]);
            if ($c < 0x80) {
                $out .= $data[$i];
            } else {
                $out .= \chr(0xC0 | ($c >> 6));
                $out .= \chr(0x80 | ($c & 0x3F));
            }
        }

        return $out;
    }

    /** UTF-8 to ISO-8859-1 (php-src ext/standard/basic_functions.c — PHP_FUNCTION(utf8_decode)). */
    public static function utf8_decode(string $data): string
    {
        $srcLen = self::byteLength($data);
        if (0 === $srcLen) {
            return '';
        }
        $out = '';
        for ($i = 0; $i < $srcLen; ) {
            $c = self::byteOrd($data[$i]);
            if ($c < 0x80) {
                $out .= $data[$i];
                ++$i;
                continue;
            }
            if (($c & 0xE0) === 0xC0) {
                if ($c < 0xC2 || $i + 1 >= $srcLen || (self::byteOrd($data[$i + 1]) & 0xC0) !== 0x80) {
                    $out .= '?';
                    ++$i;
                    continue;
                }
                $cp = (($c & 0x1F) << 6) | (self::byteOrd($data[$i + 1]) & 0x3F);
                $out .= \chr($cp <= 0xFF ? $cp : 0x3F);
                $i += 2;
                continue;
            }
            if (($c & 0xF0) === 0xE0) {
                if ($i + 2 >= $srcLen
                    || (self::byteOrd($data[$i + 1]) & 0xC0) !== 0x80
                    || (self::byteOrd($data[$i + 2]) & 0xC0) !== 0x80) {
                    $out .= '?';
                    ++$i;
                    continue;
                }
                $cp = (($c & 0x0F) << 12)
                    | ((self::byteOrd($data[$i + 1]) & 0x3F) << 6)
                    | (self::byteOrd($data[$i + 2]) & 0x3F);
                $out .= \chr($cp >= 0x800 && $cp <= 0xFF ? $cp : 0x3F);
                $i += 3;
                continue;
            }
            if (($c & 0xF8) === 0xF0) {
                if ($i + 3 >= $srcLen
                    || (self::byteOrd($data[$i + 1]) & 0xC0) !== 0x80
                    || (self::byteOrd($data[$i + 2]) & 0xC0) !== 0x80
                    || (self::byteOrd($data[$i + 3]) & 0xC0) !== 0x80) {
                    $out .= '?';
                    ++$i;
                    continue;
                }
                $out .= '?';
                $i += 4;
                continue;
            }
            $out .= '?';
            ++$i;
        }

        return $out;
    }

    /** application/x-www-form-urlencoded (space as '+'). */
    public static function urlencode(string $data): string
    {
        return self::percentEncode($data, true);
    }

    /** RFC 3986 raw encoding (space as %20). */
    public static function rawurlencode(string $data): string
    {
        return self::percentEncode($data, false);
    }

    /** application/x-www-form-urlencoded decode ('+' as space). */
    public static function urldecode(string $data): string
    {
        return self::percentDecode($data, true);
    }

    /** RFC 3986 percent-decode (does not map '+' to space). */
    public static function rawurldecode(string $data): string
    {
        return self::percentDecode($data, false);
    }

    /**
     * Cryptographically secure pseudo-random bytes (libc getrandom /dev/urandom via {@see VmRandomNative}).
     *
     * @throws \ValueError when length is less than 1
     * @throws \Exception when the operating system cannot supply random data
     */
    /**
     * uniqid() subset: VmDate::wallClock() id + optional 8 hex entropy chars (#2219, #8402).
     */
    public static function uniqid(string $prefix = '', bool $moreEntropy = false): string
    {
        $tv = VmDate::wallClock();
        $usec = $tv['usec'] % 0x100000;
        $core = \sprintf('%08x%05x', $tv['sec'], $usec);
        if ($moreEntropy) {
            try {
                $rnd = self::randomBytes(4);
                $bytes = \unpack('N', $rnd)[1];
            } catch (\Throwable $e) {
                $bytes = ($tv['usec'] ^ $tv['sec']) & 0xFFFFFFFF;
            }
            $seed = ((float) $bytes / (float) 0xFFFFFFFF) * 10.0;
            $core .= \sprintf('%.8F', $seed);
        }

        return $prefix.$core;
    }

    public static function randomBytes(int $length): string
    {
        return VmRandomNative::randomBytes($length);
    }

    /**
     * parse_url() — php-src ext/standard/url.c parity (#4458).
     *
     * @return array|string|int|null|false
     */
    public static function parseUrl(string $url, int $component = -1)
    {
        $scheme = null;
        $host = null;
        $port = 0;
        $hasPort = false;
        $user = null;
        $pass = null;
        $path = null;
        $query = null;
        $fragment = null;
        $rest = $url;
        $hadAuthority = false;

        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $rest, $m)) {
            $scheme = strtolower($m[1]);
            $rest = substr($rest, strlen($m[0]));
            $auth = self::parseUrlAuthority($rest, $host, $port, $hasPort, $user, $pass);
            if (null === $auth) {
                return false;
            }
            $hadAuthority = $auth;
        } elseif (str_starts_with($rest, '//')) {
            $auth = self::parseUrlAuthority($rest, $host, $port, $hasPort, $user, $pass);
            if (null === $auth) {
                return false;
            }
            $hadAuthority = $auth;
        }

        if ($hadAuthority && (null === $host || '' === $host)) {
            return false;
        }

        if ('' === $url) {
            $path = '';
        }

        if (str_contains($rest, '#')) {
            [$rest, $fragment] = explode('#', $rest, 2);
        }
        if (str_contains($rest, '?')) {
            [$path, $query] = explode('?', $rest, 2);
        } elseif ('' !== $rest) {
            $path = $rest;
        }

        $host = self::replaceUrlControlChars($host);
        $user = self::replaceUrlControlChars($user);
        $pass = self::replaceUrlControlChars($pass);
        $path = self::replaceUrlControlChars($path);
        $query = self::replaceUrlControlChars($query);
        $fragment = self::replaceUrlControlChars($fragment);

        if (-1 === $component) {
            $filtered = [];
            if (null !== $scheme && '' !== $scheme) {
                $filtered['scheme'] = $scheme;
            }
            if (null !== $host && '' !== $host) {
                $filtered['host'] = $host;
            }
            if ($hasPort) {
                $filtered['port'] = $port;
            }
            // Empty user/pass are still present when userinfo used ':' / lone '@' (php-src url.c).
            if (null !== $user) {
                $filtered['user'] = $user;
            }
            if (null !== $pass) {
                $filtered['pass'] = $pass;
            }
            if (null !== $path && ('' !== $path || '' === $url)) {
                $filtered['path'] = $path;
            }
            // Empty query/fragment retained when '?' / '#' was present (php-src url.c, #24400).
            if (null !== $query) {
                $filtered['query'] = $query;
            }
            if (null !== $fragment) {
                $filtered['fragment'] = $fragment;
            }

            return $filtered;
        }

        switch ($component) {
            case VmParseUrl::PHP_URL_SCHEME:
                return null !== $scheme && '' !== $scheme ? $scheme : null;
            case VmParseUrl::PHP_URL_HOST:
                return null !== $host && '' !== $host ? $host : null;
            case VmParseUrl::PHP_URL_PORT:
                return $hasPort ? $port : null;
            case VmParseUrl::PHP_URL_USER:
                return null !== $user ? $user : null;
            case VmParseUrl::PHP_URL_PASS:
                return null !== $pass ? $pass : null;
            case VmParseUrl::PHP_URL_PATH:
                return null !== $path && ('' !== $path || '' === $url) ? $path : null;
            case VmParseUrl::PHP_URL_QUERY:
                return null !== $query ? $query : null;
            case VmParseUrl::PHP_URL_FRAGMENT:
                return null !== $fragment ? $fragment : null;
            default:
                throw new \ValueError(sprintf(
                    'parse_url(): Argument #2 ($component) must be a valid URL component identifier, %d given',
                    $component
                ));
        }
    }

    /**
     * php-src url.c php_replace_controlchars — control bytes become underscore (#13553).
     */
    private static function replaceUrlControlChars(?string $value): ?string
    {
        if (null === $value || '' === $value) {
            return $value;
        }
        $len = self::byteLength($value);
        $out = '';
        $changed = false;
        for ($i = 0; $i < $len; ++$i) {
            $ord = self::byteOrd($value[$i]);
            if ($ord < 0x20 || 0x7f === $ord) {
                $out .= '_';
                $changed = true;
            } else {
                $out .= $value[$i];
            }
        }

        return $changed ? $out : $value;
    }

    /**
     * Parse //authority from $rest when present (scheme-relative or post-scheme URLs).
     *
     * @return bool|null true=authority ok, false=no //authority, null=invalid (whole URL false)
     */
    private static function parseUrlAuthority(
        string &$rest,
        ?string &$host,
        int &$port,
        bool &$hasPort,
        ?string &$user,
        ?string &$pass
    ): ?bool {
        if (!str_starts_with($rest, '//')) {
            return false;
        }
        $rest = substr($rest, 2);
        $slash = strpos($rest, '/');
        $q = strpos($rest, '?');
        $hash = strpos($rest, '#');
        $end = self::minPositive([$slash, $q, $hash]);
        $authority = false === $end ? $rest : substr($rest, 0, $end);
        $rest = false === $end ? '' : substr($rest, $end);
        if (str_contains($authority, '@')) {
            $atPos = strrpos($authority, '@');
            $userinfo = substr($authority, 0, $atPos);
            $authority = substr($authority, $atPos + 1);
            // php-src always allocates user (and pass when ':' present), including empty strings.
            $colonPos = strpos($userinfo, ':');
            if (false !== $colonPos) {
                $user = substr($userinfo, 0, $colonPos);
                $pass = substr($userinfo, $colonPos + 1);
            } else {
                $user = $userinfo;
            }
        }
        if (str_starts_with($authority, '[')) {
            $closeBracket = strpos($authority, ']');
            if (false !== $closeBracket) {
                $host = substr($authority, 0, $closeBracket + 1);
                $remainder = substr($authority, $closeBracket + 1);
                if ('' !== $remainder && ':' === $remainder[0]) {
                    $portStatus = self::parseUrlPortString(substr($remainder, 1), $port, $hasPort);
                    if (null === $portStatus) {
                        return null;
                    }
                }
            } else {
                $host = $authority;
            }
        } elseif (str_contains($authority, ':')) {
            [$host, $portStr] = explode(':', $authority, 2);
            $portStatus = self::parseUrlPortString($portStr, $port, $hasPort);
            if (null === $portStatus) {
                return null;
            }
        } else {
            $host = $authority;
        }

        return true;
    }

    /**
     * php-src url.c port scan after host — length ≤5, ZEND_STRTOL 0..65535 or fail whole URL (#22822).
     *
     * @return bool|null true=port set or empty (no port), null=invalid port → parse_url false
     */
    private static function parseUrlPortString(string $portStr, int &$port, bool &$hasPort): ?bool
    {
        $len = self::byteLength($portStr);
        if (0 === $len) {
            return true;
        }
        if ($len > 5) {
            return null;
        }
        // Match ZEND_STRTOL: leading optional sign + digits; trailing garbage ignored (e.g. 80abc → 80).
        if (1 !== preg_match('/^[+-]?\d+/', $portStr, $m)) {
            return null;
        }
        $portVal = (int) $m[0];
        if ($portVal < 0 || $portVal > 65535) {
            return null;
        }
        $port = $portVal;
        $hasPort = true;

        return true;
    }

    private static function percentEncode(string $data, bool $formEncoding): string
    {
        $len = self::byteLength($data);
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $data[$i];
            $ord = self::byteOrd($ch);
            if (
                ($ord >= 48 && $ord <= 57)
                || ($ord >= 65 && $ord <= 90)
                || ($ord >= 97 && $ord <= 122)
                || $ch === '-' || $ch === '_' || $ch === '.'
                || (!$formEncoding && $ch === '~')
            ) {
                $out .= $ch;
            } elseif ($formEncoding && $ch === ' ') {
                $out .= '+';
            } else {
                $out .= '%' . strtoupper(self::bin2hex($ch));
            }
        }

        return $out;
    }

    private static function percentDecode(string $data, bool $formDecoding): string
    {
        $len = self::byteLength($data);
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $data[$i];
            if ($formDecoding && '+' === $ch) {
                $out .= ' ';
                continue;
            }
            if ('%' === $ch && $i + 2 < $len) {
                $hi = self::hexDigit(self::byteOrd($data[$i + 1]));
                $lo = self::hexDigit(self::byteOrd($data[$i + 2]));
                if (null !== $hi && null !== $lo) {
                    $out .= \chr(($hi << 4) | $lo);
                    $i += 2;
                    continue;
                }
            }
            $out .= $ch;
        }

        return $out;
    }

    private static function hexDigit(int $ord): ?int
    {
        if ($ord >= 48 && $ord <= 57) {
            return $ord - 48;
        }
        if ($ord >= 65 && $ord <= 70) {
            return $ord - 55;
        }
        if ($ord >= 97 && $ord <= 102) {
            return $ord - 87;
        }

        return null;
    }

    /**
     * @param list<int|false> $candidates
     */
    private static function minPositive(array $candidates)
    {
        $min = false;
        foreach ($candidates as $c) {
            if (false === $c) {
                continue;
            }
            if (false === $min || $c < $min) {
                $min = $c;
            }
        }

        return $min;
    }

    /**
     * @return list<string>
     */
    public static function strSplit(string $string, int $length = 1): array
    {
        if ($length < 1) {
            throw new \ValueError('str_split(): Argument #2 ($length) must be greater than 0');
        }
        $len = self::byteLength($string);
        if (0 === $len) {
            return [];
        }
        $parts = [];
        for ($offset = 0; $offset < $len; $offset += $length) {
            $take = $length;
            if ($offset + $take > $len) {
                $take = $len - $offset;
            }
            $parts[] = self::byteSlice($string, $offset, $take);
        }

        return $parts;
    }

    public static function repeat(string $input, int $multiplier): string
    {
        if ($multiplier < 0) {
            throw new \ValueError('str_repeat(): Argument #2 ($times) must be greater than or equal to 0');
        }
        if (0 === $multiplier) {
            return '';
        }
        $inputLen = self::byteLength($input);
        if (0 === $inputLen) {
            return '';
        }
        $out = '';
        for ($i = 0; $i < $multiplier; ++$i) {
            $out .= $input;
        }

        return $out;
    }

    private static function repeatPadString(string $padString, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        $padding = '';
        while (self::byteLength($padding) < $length) {
            $padding .= $padString;
        }

        return self::byteSlice($padding, 0, $length);
    }

    /**
     * str_pad() / mb_str_pad() $pad_type — Z_PARAM_LONG soft-null DEP+coerce (#29353).
     *
     * php-src: ext/standard/string.c PHP_FUNCTION(str_pad); null → 0 (STR_PAD_LEFT).
     * Caller strict_types → TypeError via {@see VmMath::parseZParamLongBuiltinArgForFrame}.
     */
    public static function resolveStrPadTypeArg(
        Variable $var,
        ?Frame $frame = null,
        string $function = 'str_pad',
        int $argIndex = 3,
        int $userArgIndex = 4
    ): int {
        $var = $var->resolveIndirect();
        $padFromEnum = self::tryPadTypeInt($var);
        if (null !== $padFromEnum) {
            return $padFromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($pad_type) must be of type PadType|int, %s given',
                $function,
                $userArgIndex,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        // Z_PARAM_LONG: E_DEPRECATED then coerce null→0; other types TypeError/coerce (#29353).
        if (null !== $frame) {
            $padType = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                $argIndex,
                $function,
                $userArgIndex,
                'pad_type'
            );
        } else {
            $padType = VmMath::parseZParamLongBuiltinArg(
                $var,
                $function,
                $userArgIndex,
                'pad_type',
                null
            );
        }
        if (!\in_array($padType, [
            StdlibConstants::STR_PAD_LEFT,
            StdlibConstants::STR_PAD_RIGHT,
            StdlibConstants::STR_PAD_BOTH,
        ], true)) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($pad_type) must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH',
                $function,
                $userArgIndex
            ));
        }

        return $padType;
    }

    public static function padTypeIntFromEnumBacking(int $backing): int
    {
        return match ($backing) {
            0 => 1,
            1 => 0,
            2 => 2,
            default => throw new \ValueError('Invalid PadType enum value '.$backing),
        };
    }

    public static function tryPadTypeInt(Variable $var): ?int
    {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return null;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        if (null === $enumClass || !self::isPadTypeEnum($enumClass->name)) {
            return null;
        }
        $entry = EnumCaseSupport::enumCaseEntryForVariable($var);
        if (null === $entry || null === $entry->backingValue) {
            throw new \LogicException('PadType case missing backing value');
        }

        return self::padTypeIntFromEnumBacking($entry->backingValue->resolveIndirect()->toInt());
    }

    private static function isPadTypeEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), 'PadType');
    }

    public static function strPad(string $input, int $padLength, string $padString = ' ', int $padType = 1): string
    {
        $inputLen = self::byteLength($input);
        if ($padLength <= 0 || $padLength <= $inputLen) {
            return $input;
        }
        if ('' === $padString) {
            // php-src string.c PHP_FUNCTION(str_pad) — Zend "must not be empty" (#29292)
            throw new \ValueError(self::emptyStringArgValueErrorMessage('str_pad', 2, 'pad_string'));
        }
        $need = $padLength - $inputLen;
        if (2 === $padType) {
            $leftNeed = intdiv($need, 2);
            $rightNeed = $need - $leftNeed;

            return self::repeatPadString($padString, $leftNeed).$input.self::repeatPadString($padString, $rightNeed);
        }
        $padding = self::repeatPadString($padString, $need);
        if (0 === $padType) {
            return $padding.$input;
        }

        return $input.$padding;
    }

    /**
     * Internal UTF-8 codepoint padding helper (mb_str_pad semantics; not a php-src userland builtin — #13581).
     */
    public static function strPadded(string $input, int $padLength, string $padString = ' ', int $padType = 1): string
    {
        $inputLength = self::utf8CharLength($input);
        if ($padLength <= 0 || $padLength <= $inputLength) {
            return $input;
        }
        if ('' === $padString) {
            throw new \ValueError('str_padded(): Argument #3 ($pad_string) must be a non-empty string');
        }
        $padCharLength = self::utf8CharLength($padString);
        if (0 === $padCharLength) {
            throw new \ValueError('str_padded(): Argument #3 ($pad_string) must be a non-empty string');
        }
        if ($padType < 0 || $padType > 2) {
            throw new \ValueError(
                'str_padded(): Argument #4 ($pad_type) must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH'
            );
        }

        $numPadChars = $padLength - $inputLength;
        if (1 === $padType) {
            $leftPad = 0;
            $rightPad = $numPadChars;
        } elseif (0 === $padType) {
            $leftPad = $numPadChars;
            $rightPad = 0;
        } else {
            $leftPad = intdiv($numPadChars, 2);
            $rightPad = $numPadChars - $leftPad;
        }

        return self::repeatUtf8PadString($padString, $padCharLength, $leftPad)
            .$input
            .self::repeatUtf8PadString($padString, $padCharLength, $rightPad);
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
            $result .= self::utf8CharSubstr($padString, 0, $remainder);
        }

        return $result;
    }

    public static function htmlspecialchars(
        string $string,
        int $flags = ENT_QUOTES | ENT_SUBSTITUTE,
        string $encoding = 'UTF-8',
        bool $doubleEncode = true
    ): string {
        if (!self::isUtf8Encoding($encoding)) {
            return \htmlspecialchars($string, $flags, $encoding, $doubleEncode);
        }
        if (!self::isValidUtf8($string)) {
            if (0 === ($flags & ENT_SUBSTITUTE)) {
                return '';
            }
            $string = self::utf8SubstituteInvalidSequences($string);
        }
        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $entHtml5 = 0 !== ($flags & ENT_HTML5);
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            switch ($ch) {
                case '&':
                    if (!$doubleEncode) {
                        $entityLen = self::htmlspecialcharsExistingEntityLen($string, $i, $len);
                        if ($entityLen > 0) {
                            $out .= substr($string, $i, $entityLen);
                            $i += $entityLen - 1;
                            break;
                        }
                    }
                    $out .= '&amp;';
                    break;
                case '<':
                    $out .= '&lt;';
                    break;
                case '>':
                    $out .= '&gt;';
                    break;
                case '"':
                    $out .= ($quoteBoth || $quoteDouble) ? '&quot;' : '"';
                    break;
                case "'":
                    $out .= $quoteBoth ? ($entHtml5 ? '&apos;' : '&#039;') : "'";
                    break;
                default:
                    $out .= $ch;
            }
        }

        return $out;
    }

    /**
     * get_html_translation_table() — character => entity map (ext/standard/html.c, #3637).
     *
     * @return \PHPCompiler\VM\HashTable
     */
    public static function getHtmlTranslationTable(
        int $table = HTML_SPECIALCHARS,
        int $flags = ENT_QUOTES | ENT_SUBSTITUTE,
        string $encoding = 'UTF-8'
    ): \PHPCompiler\VM\HashTable {
        if (!self::isUtf8Encoding($encoding)) {
            return self::getHtmlTranslationTableViaZend($table, $flags, $encoding);
        }
        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $entHtml5 = 0 !== ($flags & ENT_HTML5);

        if (!$table) {
            $entries = [
                '&' => '&amp;',
                '<' => '&lt;',
                '>' => '&gt;',
            ];
            if ($quoteBoth || $quoteDouble) {
                $entries['"'] = '&quot;';
            }
            if ($quoteBoth) {
                $entries["'"] = $entHtml5 ? '&apos;' : '&#039;';
            }
        } else {
            $entries = $entHtml5
                ? Html5TranslationTable::entities()
                : HtmlEntityTable::entitiesEntQuotes();
            if ($quoteBoth || $quoteDouble) {
                $entries['"'] = '&quot;';
            }
            if ($quoteBoth) {
                $entries["'"] = $entHtml5 ? '&apos;' : '&#039;';
            }
            if (!$quoteBoth && !$quoteDouble) {
                unset($entries['"']);
            }
            if (!$quoteBoth) {
                unset($entries["'"]);
            }
        }

        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($entries as $key => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->string($value);
            $ht->add($key, $var);
        }

        return $ht;
    }

    /**
     * Non-UTF-8 encodings delegate to Zend (ext/standard/html.c, #4459).
     *
     * @return \PHPCompiler\VM\HashTable
     */
    private static function getHtmlTranslationTableViaZend(
        int $table,
        int $flags,
        string $encoding
    ): \PHPCompiler\VM\HashTable {
        $native = \get_html_translation_table($table, $flags, $encoding);
        if (!\is_array($native)) {
            $ht = new \PHPCompiler\VM\HashTable();

            return $ht;
        }

        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($native as $key => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->string((string) $value);
            $ht->add((string) $key, $var);
        }

        return $ht;
    }

    /** htmlentities() — full HTML_ENTITIES table for UTF-8 (#10734, ext/standard/html.c). */
    public static function htmlentities(
        string $string,
        int $flags = ENT_QUOTES | ENT_SUBSTITUTE,
        string $encoding = 'UTF-8',
        bool $doubleEncode = true
    ): string {
        if (!self::isUtf8Encoding($encoding)) {
            return \htmlentities($string, $flags, $encoding, $doubleEncode);
        }
        if (!self::isValidUtf8($string)) {
            if (0 === ($flags & ENT_SUBSTITUTE)) {
                return '';
            }
            $string = self::utf8SubstituteInvalidSequences($string);
        }
        $entries = self::htmlEntitiesMapForFlags($flags);
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len;) {
            $width = self::utf8CharByteWidth($string, $i);
            $char = \substr($string, $i, $width);
            if ('&' === $char[0] && !$doubleEncode) {
                $entityLen = self::htmlentitiesExistingEntityLen($string, $i, $len);
                if ($entityLen > 0) {
                    $out .= \substr($string, $i, $entityLen);
                    $i += $entityLen;
                    continue;
                }
            }
            $out .= $entries[$char] ?? $char;
            $i += $width;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function htmlEntitiesMapForFlags(int $flags): array
    {
        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $entHtml5 = 0 !== ($flags & ENT_HTML5);
        $entries = HtmlEntityTable::entitiesEntQuotes();
        if (!$quoteBoth && !$quoteDouble) {
            unset($entries['"']);
        }
        if ($quoteBoth) {
            $entries["'"] = $entHtml5 ? '&apos;' : '&#039;';
        } else {
            unset($entries["'"]);
        }

        return $entries;
    }

    /**
     * htmlspecialchars_decode() — inverse of {@see htmlspecialchars()} for our entity subset.
     */
    public static function htmlspecialchars_decode(
        string $string,
        int $flags = ENT_QUOTES | ENT_SUBSTITUTE
    ): string {
        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $out = '';
        $len = self::byteLength($string);
        $i = 0;
        while ($i < $len) {
            if ('&' !== $string[$i]) {
                $out .= $string[$i];
                ++$i;
                continue;
            }
            if (self::entityAt($string, $i, $len, '&amp;', 5)) {
                $out .= '&';
                $i += 5;
            } elseif (self::entityAt($string, $i, $len, '&lt;', 4)) {
                $out .= '<';
                $i += 4;
            } elseif (self::entityAt($string, $i, $len, '&gt;', 4)) {
                $out .= '>';
                $i += 4;
            } elseif (($quoteBoth || $quoteDouble) && self::entityAt($string, $i, $len, '&quot;', 6)) {
                $out .= '"';
                $i += 6;
            } elseif ($quoteBoth && self::entityAt($string, $i, $len, '&#039;', 6)) {
                $out .= "'";
                $i += 6;
            } elseif ($quoteBoth && self::entityAt($string, $i, $len, '&#39;', 5)) {
                $out .= "'";
                $i += 5;
            } elseif (0 !== ($flags & ENT_HTML5) && ENT_QUOTES === ($flags & ENT_QUOTES)
                && self::entityAt($string, $i, $len, '&apos;', 6)) {
                $out .= "'";
                $i += 6;
            } else {
                $out .= '&';
                ++$i;
            }
        }

        return $out;
    }

    /** html_entity_decode() — ENT_HTML5 named entities (php-src html.c); default ENT_COMPAT (#2472). */
    public static function html_entity_decode(
        string $string,
        int $flags = ENT_QUOTES | ENT_SUBSTITUTE,
        string $encoding = 'UTF-8'
    ): string {
        if (!self::isUtf8Encoding($encoding)) {
            return \html_entity_decode($string, $flags, $encoding);
        }
        if (0 !== ($flags & ENT_HTML5)) {
            return self::htmlEntityDecodeHtml5($string, $flags);
        }

        return self::htmlEntityDecodeHtml401($string, $flags);
    }

    /** html_entity_decode() default path — HTML401 named entities + basic flags (#10763). */
    private static function htmlEntityDecodeHtml401(string $string, int $flags): string
    {
        $decodeDouble = 0 !== ($flags & 2);
        $decodeSingle = 0 !== ($flags & 1);
        $namedEntities = self::htmlEntityDecodeMap();
        $out = '';
        $len = self::byteLength($string);
        $i = 0;
        while ($i < $len) {
            if ('&' !== $string[$i]) {
                $out .= $string[$i];
                ++$i;
                continue;
            }
            if (self::entityAt($string, $i, $len, '&amp;', 5)) {
                $out .= '&';
                $i += 5;
                continue;
            }
            if (self::entityAt($string, $i, $len, '&lt;', 4)) {
                $out .= '<';
                $i += 4;
                continue;
            }
            if (self::entityAt($string, $i, $len, '&gt;', 4)) {
                $out .= '>';
                $i += 4;
                continue;
            }
            if ($decodeDouble && self::entityAt($string, $i, $len, '&quot;', 6)) {
                $out .= '"';
                $i += 6;
                continue;
            }
            if ($decodeSingle && self::entityAt($string, $i, $len, '&#039;', 6)) {
                $out .= "'";
                $i += 6;
                continue;
            }
            if ($decodeSingle && self::entityAt($string, $i, $len, '&#39;', 5)) {
                $out .= "'";
                $i += 5;
                continue;
            }

            $semi = strpos($string, ';', $i + 1);
            if (false !== $semi && $semi > $i + 1 && $semi - $i <= 33) {
                $entity = substr($string, $i, $semi - $i + 1);
                $decoded = $namedEntities[$entity] ?? null;
                if (null !== $decoded) {
                    if ("'" === $decoded && !$decodeSingle) {
                        $out .= $entity;
                        $i = $semi + 1;
                        continue;
                    }
                    if ('"' === $decoded && !$decodeDouble) {
                        $out .= $entity;
                        $i = $semi + 1;
                        continue;
                    }
                    $out .= $decoded;
                    $i = $semi + 1;
                    continue;
                }
            }

            $numericLen = self::htmlspecialcharsNumericEntityLen($string, $i, $len);
            if ($numericLen > 0) {
                $entity = substr($string, $i, $numericLen);
                $decoded = self::decodeHtmlNumericEntity($entity);
                if (null !== $decoded) {
                    $out .= $decoded;
                    $i += $numericLen;
                    continue;
                }
            }

            $out .= '&';
            ++$i;
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function htmlEntityDecodeMap(): array
    {
        static $map = null;
        if (null === $map) {
            $map = [];
            foreach (HtmlEntityTable::entitiesEntQuotes() as $char => $entity) {
                $map[$entity] = $char;
            }
            // &apos; is HTML5/XHTML only — ENT_HTML401 leaves it unchanged (ext/standard/html.c, #13948).
        }

        return $map;
    }

    private static function decodeHtmlNumericEntity(string $entity): ?string
    {
        if (!str_starts_with($entity, '&#') || !str_ends_with($entity, ';')) {
            return null;
        }
        $body = substr($entity, 2, -1);
        if ('' === $body) {
            return null;
        }
        if ('x' === $body[0] || 'X' === $body[0]) {
            $code = hexdec(substr($body, 1));
        } else {
            if (!ctype_digit($body)) {
                return null;
            }
            $code = (int) $body;
        }
        if ($code < 0 || $code > 0x10FFFF) {
            return null;
        }
        if ($code <= 0x7F) {
            return \chr($code);
        }
        if ($code <= 0x7FF) {
            return \chr(0xC0 | ($code >> 6)).\chr(0x80 | ($code & 0x3F));
        }
        if ($code <= 0xFFFF) {
            return \chr(0xE0 | ($code >> 12))
                .\chr(0x80 | (($code >> 6) & 0x3F))
                .\chr(0x80 | ($code & 0x3F));
        }

        return \chr(0xF0 | ($code >> 18))
            .\chr(0x80 | (($code >> 12) & 0x3F))
            .\chr(0x80 | (($code >> 6) & 0x3F))
            .\chr(0x80 | ($code & 0x3F));
    }

    /**
     * html_entity_decode() with ENT_HTML5 — full HTML5 named-entity table (ext/standard/html_tables.h).
     */
    private static function htmlEntityDecodeHtml5(string $string, int $flags): string
    {
        $decodeDouble = 0 !== ($flags & 2);
        $decodeSingle = 0 !== ($flags & 1);
        $out = '';
        $len = self::byteLength($string);
        $i = 0;
        while ($i < $len) {
            if ('&' !== $string[$i]) {
                $out .= $string[$i];
                ++$i;
                continue;
            }
            if (self::entityAt($string, $i, $len, '&amp;', 5)) {
                $out .= '&';
                $i += 5;
                continue;
            }
            if (self::entityAt($string, $i, $len, '&lt;', 4)) {
                $out .= '<';
                $i += 4;
                continue;
            }
            if (self::entityAt($string, $i, $len, '&gt;', 4)) {
                $out .= '>';
                $i += 4;
                continue;
            }
            if ($decodeDouble && self::entityAt($string, $i, $len, '&quot;', 6)) {
                $out .= '"';
                $i += 6;
                continue;
            }
            if ($decodeSingle && self::entityAt($string, $i, $len, '&#039;', 6)) {
                $out .= "'";
                $i += 6;
                continue;
            }
            if ($decodeSingle && self::entityAt($string, $i, $len, '&#39;', 5)) {
                $out .= "'";
                $i += 5;
                continue;
            }

            $semi = strpos($string, ';', $i + 1);
            if (false !== $semi && $semi > $i + 1 && $semi - $i - 1 <= 32) {
                $name = substr($string, $i + 1, $semi - $i - 1);
                if (ctype_alnum($name[0])) {
                    $decoded = Html5NamedEntities::lookup($name);
                    if (null !== $decoded) {
                        if ("'" === $decoded && !$decodeSingle) {
                            $out .= substr($string, $i, $semi - $i);
                            $i = $semi;
                            continue;
                        }
                        if ('"' === $decoded && !$decodeDouble) {
                            $out .= substr($string, $i, $semi - $i);
                            $i = $semi;
                            continue;
                        }
                        $out .= $decoded;
                        $i = $semi + 1;
                        continue;
                    }
                }
            }

            $numericLen = self::htmlspecialcharsNumericEntityLen($string, $i, $len);
            if ($numericLen > 0) {
                $entity = substr($string, $i, $numericLen);
                $decoded = self::decodeHtmlNumericEntity($entity);
                if (null !== $decoded) {
                    $out .= $decoded;
                    $i += $numericLen;
                    continue;
                }
            }

            $out .= '&';
            ++$i;
        }

        return $out;
    }

    private static function entityAt(string $string, int $pos, int $len, string $entity, int $entityLen): bool
    {
        if ($pos + $entityLen > $len) {
            return false;
        }
        for ($j = 0; $j < $entityLen; ++$j) {
            if ($string[$pos + $j] !== $entity[$j]) {
                return false;
            }
        }

        return true;
    }

    private static function isUtf8Encoding(string $encoding): bool
    {
        return 0 === strcasecmp($encoding, 'UTF-8');
    }

    /**
     * Length of an existing HTML entity at $pos when $double_encode=false (php-src html.c parity).
     */
    private static function htmlspecialcharsExistingEntityLen(string $string, int $pos, int $len): int
    {
        if ($pos >= $len || '&' !== $string[$pos]) {
            return 0;
        }
        foreach ([
            ['&amp;', 5],
            ['&lt;', 4],
            ['&gt;', 4],
            ['&quot;', 6],
            ['&#039;', 6],
            ['&#39;', 5],
        ] as [$entity, $entityLen]) {
            if (self::entityAt($string, $pos, $len, $entity, $entityLen)) {
                return $entityLen;
            }
        }

        return self::htmlspecialcharsNumericEntityLen($string, $pos, $len);
    }

    /** Named/numeric entity length for htmlentities() when $double_encode=false (#10734). */
    private static function htmlentitiesExistingEntityLen(string $string, int $pos, int $len): int
    {
        $basic = self::htmlspecialcharsExistingEntityLen($string, $pos, $len);
        if ($basic > 0) {
            return $basic;
        }
        if ($pos >= $len || '&' !== $string[$pos]) {
            return 0;
        }
        $semi = \strpos($string, ';', $pos + 1);
        if (false === $semi || $semi <= $pos + 1 || $semi - $pos > 33) {
            return 0;
        }
        $candidate = \substr($string, $pos, $semi - $pos + 1);
        if (isset(self::htmlEntityLiteralSet()[$candidate])) {
            return \strlen($candidate);
        }

        return 0;
    }

    /** @return array<string, true> */
    private static function htmlEntityLiteralSet(): array
    {
        static $set = null;
        if (null === $set) {
            $set = [];
            foreach (HtmlEntityTable::entitiesEntQuotes() as $entity) {
                $set[$entity] = true;
            }
            $set['&apos;'] = true;
        }

        return $set;
    }

    /** @return int byte length including leading & and trailing ;, or 0 if not a numeric entity */
    private static function htmlspecialcharsNumericEntityLen(string $string, int $pos, int $len): int
    {
        if ($pos + 3 > $len || '&' !== $string[$pos] || '#' !== $string[$pos + 1]) {
            return 0;
        }
        $i = $pos + 2;
        if ($i >= $len) {
            return 0;
        }
        if ('x' === $string[$i] || 'X' === $string[$i]) {
            ++$i;
            if ($i >= $len || !ctype_xdigit($string[$i])) {
                return 0;
            }
            while ($i < $len && ctype_xdigit($string[$i])) {
                ++$i;
            }
        } else {
            if (!ctype_digit($string[$i])) {
                return 0;
            }
            while ($i < $len && ctype_digit($string[$i])) {
                ++$i;
            }
        }
        if ($i >= $len || ';' !== $string[$i]) {
            return 0;
        }

        return $i - $pos + 1;
    }

    /**
     * strip_tags() subset: removes HTML/PHP tags; optional allow-list like "<b><p>" or ['b','p'].
     * HTML comments and PHP tags remove their inner content; other tags keep inner text.
     *
     * @param string|list<string>|null $allowedTags
     */
    public static function stripTags(string $string, string|array|null $allowedTags = null): string
    {
        $allowed = self::normalizeStripTagsAllowed($allowedTags);
        $out = '';
        $len = self::byteLength($string);
        $i = 0;
        while ($i < $len) {
            $ch = $string[$i];
            if ('<' !== $ch) {
                $out .= $ch;
                ++$i;
                continue;
            }
            if ($i + 3 < $len && '<!--' === self::byteSlice($string, $i, 4)) {
                $end = self::findSubstring($string, '-->', $i + 4);
                if (false !== $end) {
                    $i = $end + 3;
                    continue;
                }
            }
            if ($i + 1 < $len && '<?' === self::byteSlice($string, $i, 2)) {
                $end = self::findSubstring($string, '?>', $i + 2);
                if (false !== $end) {
                    $i = $end + 2;
                    continue;
                }
            }
            $gt = self::findSubstring($string, '>', $i + 1);
            if (false === $gt) {
                $out .= $ch;
                ++$i;
                continue;
            }
            $tagContent = self::byteSlice($string, $i + 1, $gt - $i - 1);
            $tagName = self::extractTagName($tagContent);
            if (null !== $tagName && [] !== $allowed && self::isTagAllowed($tagName, $allowed)) {
                $out .= self::byteSlice($string, $i, $gt - $i + 1);
            }
            $i = $gt + 1;
        }

        return $out;
    }

    /**
     * @param string|list<string>|null $allowedTags
     *
     * @return list<string>
     */
    public static function normalizeStripTagsAllowed(string|array|null $allowedTags): array
    {
        if (null === $allowedTags) {
            return [];
        }
        if (\is_array($allowedTags)) {
            return self::normalizeAllowedTagNames($allowedTags);
        }

        return '' === $allowedTags ? [] : self::parseAllowedTags($allowedTags);
    }

    /**
     * Build {@code <a><b>} markup from plain tag names (php-src array allowed_tags branch).
     *
     * @param list<string> $tagNames
     */
    public static function formatAllowedTagsMarkup(array $tagNames): string
    {
        $parts = [];
        foreach (self::normalizeAllowedTagNames($tagNames) as $name) {
            $parts[] = '<'.$name.'>';
        }

        return implode('', $parts);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function normalizeAllowedTagNames(array $names): array
    {
        $tags = [];
        foreach ($names as $name) {
            $name = strtolower($name);
            if ('' !== $name) {
                $tags[] = $name;
            }
        }

        return $tags;
    }

    /**
     * @return list<string>
     */
    private static function parseAllowedTags(string $allowedTags): array
    {
        $tags = [];
        $len = self::byteLength($allowedTags);
        $i = 0;
        while ($i < $len) {
            if ('<' !== $allowedTags[$i]) {
                ++$i;
                continue;
            }
            $gt = self::findSubstring($allowedTags, '>', $i + 1);
            if (false === $gt) {
                break;
            }
            $name = self::extractTagName(self::byteSlice($allowedTags, $i + 1, $gt - $i - 1));
            if (null !== $name && '' !== $name) {
                $tags[] = $name;
            }
            $i = $gt + 1;
        }

        return $tags;
    }

    private static function extractTagName(string $tagContent): ?string
    {
        $len = self::byteLength($tagContent);
        $i = 0;
        while ($i < $len && self::isTagWhitespace($tagContent[$i])) {
            ++$i;
        }
        if ($i < $len && '/' === $tagContent[$i]) {
            ++$i;
        }
        if ($i >= $len) {
            return null;
        }
        $start = $i;
        while ($i < $len) {
            $ch = $tagContent[$i];
            if (self::isTagWhitespace($ch) || '>' === $ch || '/' === $ch) {
                break;
            }
            if (!ctype_alpha($ch) && !ctype_digit($ch)) {
                return null;
            }
            ++$i;
        }
        if ($start === $i) {
            return null;
        }

        return strtolower(self::byteSlice($tagContent, $start, $i - $start));
    }

    /**
     * @param list<string> $allowed
     */
    private static function isTagAllowed(string $tagName, array $allowed): bool
    {
        $tagName = strtolower($tagName);
        foreach ($allowed as $name) {
            if ($tagName === $name) {
                return true;
            }
        }

        return false;
    }

    private static function isTagWhitespace(string $ch): bool
    {
        return str_contains(self::TRIM_DEFAULT, $ch);
    }

    /**
     * @return list<string>
     */
    public static function explode(string $delimiter, string $string, int $limit = \PHP_INT_MAX): array
    {
        if ('' === $delimiter) {
            self::rejectEmptyBuiltinStringArg($delimiter, 'explode', 0, 'separator', true);
        }
        if ('' === $string) {
            if ($limit >= 0) {
                return [''];
            }

            return [];
        }
        if ($limit > 1) {
            return self::explodePositiveLimit($delimiter, $string, $limit);
        }
        if ($limit < 0) {
            return self::explodeNegativeLimit($delimiter, $string, $limit);
        }

        return [$string];
    }

    /**
     * php-src ext/standard/string.c — php_explode().
     *
     * @return list<string>
     */
    private static function explodePositiveLimit(string $delimiter, string $string, int $limit): array
    {
        $parts = [];
        $offset = 0;
        $delimLen = self::byteLength($delimiter);
        $strLen = self::byteLength($string);
        $pos = self::findSubstring($string, $delimiter, $offset);
        if (false === $pos) {
            return [self::byteSlice($string, $offset)];
        }
        do {
            $parts[] = self::byteSlice($string, $offset, $pos - $offset);
            $offset = $pos + $delimLen;
            $pos = self::findSubstring($string, $delimiter, $offset);
            --$limit;
        } while (false !== $pos && $limit > 1);
        if ($offset <= $strLen) {
            $parts[] = self::byteSlice($string, $offset);
        }

        return $parts;
    }

    /**
     * php-src ext/standard/string.c — php_explode_negative_limit().
     *
     * @return list<string>
     */
    private static function explodeNegativeLimit(string $delimiter, string $string, int $limit): array
    {
        $delimLen = self::byteLength($delimiter);
        $strLen = self::byteLength($string);
        $positions = [0];
        $offset = 0;
        while (true) {
            $pos = self::findSubstring($string, $delimiter, $offset);
            if (false === $pos) {
                break;
            }
            $offset = $pos + $delimLen;
            $positions[] = $offset;
        }
        $found = \count($positions);
        $toReturn = $limit + $found;
        if ($toReturn <= 0) {
            return [];
        }
        $parts = [];
        for ($i = 0; $i < $toReturn; ++$i) {
            $start = $positions[$i];
            $end = ($i + 1 < $found)
                ? $positions[$i + 1] - $delimLen
                : $strLen;
            $parts[] = self::byteSlice($string, $start, $end - $start);
        }

        return $parts;
    }

    /**
     * @param list<string> $parts
     */
    public static function implode(string $glue, array $parts): string
    {
        if ([] === $parts) {
            return '';
        }
        $result = $parts[0];
        $count = count($parts);
        for ($i = 1; $i < $count; ++$i) {
            $result .= $glue.$parts[$i];
        }

        return $result;
    }

    public static function substr(
        string $string,
        int $offset,
        ?int $length = null,
        bool $warnOnClip = false,
        ?\PHPCompiler\Frame $frame = null,
        string $function = 'substr',
    ): string {
        return self::byteSlice($string, $offset, $length, $warnOnClip, $frame, $function);
    }

    private const SUBSTR_TRUNCATED_WARNING = '%s(): String is truncated';

    private static function emitSubstrTruncatedWarning(?\PHPCompiler\Frame $frame, string $function): void
    {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            \sprintf(self::SUBSTR_TRUNCATED_WARNING, $function),
            \PHPCompiler\VM\ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    /**
     * Whether a positive length would extend past the end of $string after $offset normalization.
     */
    public static function substrLengthWouldClip(string $string, int $offset, int $length): bool
    {
        if ($length <= 0) {
            return false;
        }
        $len = self::byteLength($string);
        if ($offset < 0) {
            $offset = $len + $offset;
            if ($offset < 0) {
                $offset = 0;
            }
        }
        if ($offset >= $len) {
            return false;
        }

        return $offset + $length > $len;
    }

    /**
     * @param list<Variable> $optionalArgs trim/ltrim/rtrim args after $string (0..1 entries)
     *
     * @return array{0: string, 1: int} character mask and php_trim_int() mode bitmask
     */
    public static function resolveTrimMaskAndMode(
        array $optionalArgs,
        string $function,
        int $defaultMode
    ): array {
        $argc = \count($optionalArgs);
        if ($argc > 1) {
            // php-src string.stub.php — arity ≤2; no $mode (#28230 / #28202).
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at most 2 arguments, %d given',
                $function,
                $argc + 1
            ));
        }
        if (0 === $argc) {
            return [self::TRIM_DEFAULT, $defaultMode];
        }

        return [
            self::coerceStringBuiltinArg($optionalArgs[0], $function, 1, 'characters'),
            $defaultMode,
        ];
    }

    public static function trimInt(string $string, string $characterMask, int $mode): string
    {
        $start = 0;
        $len = self::byteLength($string);
        if ($mode & self::TRIM_SIDE_LEFT) {
            while ($start < $len && self::charInMask($string[$start], $characterMask)) {
                ++$start;
            }
        }
        if ($mode & self::TRIM_SIDE_RIGHT) {
            if ($start === $len) {
                return '';
            }
            $end = $len - 1;
            while ($end >= $start && self::charInMask($string[$end], $characterMask)) {
                --$end;
            }

            return self::byteSlice($string, $start, $end - $start + 1);
        }

        return self::byteSlice($string, $start);
    }

    public static function trim(string $string, string $characterMask = self::TRIM_DEFAULT): string
    {
        return self::trimInt($string, $characterMask, self::TRIM_SIDE_BOTH);
    }

    public static function ltrim(string $string, string $characterMask = self::TRIM_DEFAULT): string
    {
        return self::trimInt($string, $characterMask, self::TRIM_SIDE_LEFT);
    }

    public static function rtrim(string $string, string $characterMask = self::TRIM_DEFAULT): string
    {
        return self::trimInt($string, $characterMask, self::TRIM_SIDE_RIGHT);
    }

    /** php-src php_charmask() membership test — public for JIT helper (#14908). */
    public static function charInTrimMask(string $ch, string $mask): bool
    {
        return self::charInMask($ch, $mask);
    }

    public static function asciiLower(string $string): string
    {
        return self::asciiCaseTransform($string, true);
    }

    public static function asciiUpper(string $string): string
    {
        return self::asciiCaseTransform($string, false);
    }

    /** str_rot13() for byte strings — ASCII letters only. */
    public static function strRot13(string $string): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            $ord = self::byteOrd($ch);
            if (($ord >= 65 && $ord <= 77) || ($ord >= 97 && $ord <= 109)) {
                $ch = self::byteChr($ord + 13);
            } elseif (($ord >= 78 && $ord <= 90) || ($ord >= 110 && $ord <= 122)) {
                $ch = self::byteChr($ord - 13);
            }
            $out .= $ch;
        }

        return $out;
    }

    /** Whether every byte is ASCII alphanumeric ([0-9A-Za-z]). */
    public static function onlyAsciiAlphanumeric(string $string): bool
    {
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ord = self::byteOrd($string[$i]);
            if (!(($ord >= 48 && $ord <= 57) || ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122))) {
                return false;
            }
        }

        return true;
    }

    /**
     * str_increment() — PHP 8.3 alphanumeric increment (ext/standard/string.c).
     */
    public static function strIncrement(string $string): string
    {
        if ('' === $string) {
            throw new \ValueError('str_increment(): Argument #1 ($string) must not be empty');
        }
        if (!self::onlyAsciiAlphanumeric($string)) {
            throw new \ValueError('str_increment(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }

        $incremented = $string;
        $len = self::byteLength($incremented);
        $position = $len - 1;
        $carry = false;

        do {
            $c = $incremented[$position];
            if ('z' !== $c && 'Z' !== $c && '9' !== $c) {
                $carry = false;
                $incremented[$position] = self::byteChr(self::byteOrd($c) + 1);
            } else {
                $carry = true;
                if ('9' === $c) {
                    $incremented[$position] = '0';
                } else {
                    $incremented[$position] = self::byteChr(self::byteOrd($c) - 25);
                }
            }
        } while ($carry && $position-- > 0);

        if ($carry) {
            $prefix = '0' === $incremented[0] ? '1' : $incremented[0];

            return $prefix.$incremented;
        }

        return $incremented;
    }

    /**
     * Zend increment_string() for ++ on string operands (issue #3469, #29658).
     *
     * Non-alphanumeric bytes stop the carry chain without peri-mutating them
     * (php-src 8.3+ / RFC saner-inc-dec-operators). Empty → {@code '1'}.
     *
     * @see Zend/zend_operators.c increment_string()
     */
    public static function incrementStringOperator(string $string): string
    {
        if ('' === $string) {
            return '1';
        }

        $incremented = $string;
        $len = self::byteLength($incremented);
        $position = $len - 1;
        $carry = false;
        $last = 0;

        // php-src increment_string(): $last tracks the last alphanumeric class seen,
        // including overflow ('z'/'Z'/'9'), so lengthening prepends the right case (#21911).
        // Non-alnum at $position: carry=0; break — do not ASCII-bump the byte (#29658).
        do {
            $c = $incremented[$position];
            $ord = self::byteOrd($c);
            if ($ord >= 97 && $ord <= 122) {
                if ('z' === $c) {
                    $incremented[$position] = 'a';
                    $carry = true;
                } else {
                    $incremented[$position] = self::byteChr($ord + 1);
                    $carry = false;
                }
                $last = 1;
            } elseif ($ord >= 65 && $ord <= 90) {
                if ('Z' === $c) {
                    $incremented[$position] = 'A';
                    $carry = true;
                } else {
                    $incremented[$position] = self::byteChr($ord + 1);
                    $carry = false;
                }
                $last = 2;
            } elseif ($ord >= 48 && $ord <= 57) {
                if ('9' === $c) {
                    $incremented[$position] = '0';
                    $carry = true;
                } else {
                    $incremented[$position] = self::byteChr($ord + 1);
                    $carry = false;
                }
                $last = 3;
            } else {
                $carry = false;
                break;
            }
            if (!$carry) {
                break;
            }
        } while ($position-- > 0);

        if ($carry) {
            $prefix = match ($last) {
                2 => 'A',
                3 => '0' === $incremented[0] ? '1' : $incremented[0],
                default => 'a',
            };

            return $prefix.$incremented;
        }

        return $incremented;
    }

    /**
     * str_decrement() — PHP 8.3 alphanumeric decrement (ext/standard/string.c).
     */
    public static function strDecrement(string $string): string
    {
        if ('' === $string) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) must not be empty');
        }
        if (!self::onlyAsciiAlphanumeric($string)) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }
        if ('0' === $string[0]) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) "'.$string.'" is out of decrement range');
        }

        $decremented = $string;
        $len = self::byteLength($decremented);
        $position = $len - 1;
        $carry = false;

        do {
            $c = $decremented[$position];
            if ('a' !== $c && 'A' !== $c && '0' !== $c) {
                $carry = false;
                $decremented[$position] = self::byteChr(self::byteOrd($c) - 1);
            } else {
                $carry = true;
                if ('0' === $c) {
                    $decremented[$position] = '9';
                } else {
                    $decremented[$position] = self::byteChr(self::byteOrd($c) + 25);
                }
            }
        } while ($carry && $position-- > 0);

        if ($carry || ('0' === $decremented[0] && $len > 1)) {
            if (1 === $len) {
                throw new \ValueError('str_decrement(): Argument #1 ($string) "'.$string.'" is out of decrement range');
            }

            return substr($decremented, 1);
        }

        return $decremented;
    }

    public static function pregQuote(string $string, ?string $delimiter = null): string
    {
        $delim = null;
        if (null !== $delimiter && '' !== $delimiter) {
            $delim = $delimiter[0];
        }
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ("\0" === $ch) {
                // php-src string.c php_preg_quote: NUL -> \000 (backslash + three ASCII zeros)
                $out .= '\\000';
                continue;
            }
            if (str_contains(self::PREG_QUOTE_ESCAPE, $ch) || (null !== $delim && $ch === $delim)) {
                $out .= '\\'.$ch;
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    public static function quotemeta(string $string): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if (str_contains(self::QUOTEMETA_ESCAPE, $ch)) {
                $out .= '\\'.$ch;
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    public static function addslashes(string $string): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ("\0" === $ch) {
                // php-src string.c php_addslashes: NUL -> backslash + ASCII '0'
                $out .= '\\0';
            } elseif (self::needsAddslashesEscape($ch)) {
                $out .= '\\'.$ch;
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    public static function stripslashes(string $string): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ('\\' === $ch && $i + 1 < $len) {
                $next = $string[$i + 1];
                // php-src stripslashes.c: drop backslash; \0 C-escape maps to NUL (addslashes inverse).
                $out .= '0' === $next ? "\0" : $next;
                ++$i;
                continue;
            }
            $out .= $ch;
        }

        return $out;
    }

    private static function needsAddslashesEscape(string $ch): bool
    {
        return '\\' === $ch || "'" === $ch || '"' === $ch;
    }

    /**
     * addcslashes() — C-style selective escaping (php-src string.c php_addcslashes_str).
     */
    public static function addcslashes(string $string, string $charlist): string
    {
        $mask = self::buildAddcslashesCharMask($charlist);
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ord = self::byteOrd($string[$i]);
            if ($mask[$ord]) {
                $out .= self::formatAddcslashesEscape($ord);
            } else {
                $out .= $string[$i];
            }
        }

        return $out;
    }

    /** Escaped output for one masked byte (php-src php_addcslashes_str; issue #4736). */
    private static function formatAddcslashesEscape(int $ord): string
    {
        if ($ord < 32 || $ord > 126) {
            return match ($ord) {
                10 => '\\n',
                9 => '\\t',
                13 => '\\r',
                7 => '\\a',
                11 => '\\v',
                8 => '\\b',
                12 => '\\f',
                default => sprintf('\\%03o', $ord),
            };
        }

        return '\\'.self::byteChr($ord);
    }

    /** @return array<int, bool> escape mask for addcslashes charlist (JIT/AOT lowering). */
    public static function addcslashesEscapeMask(string $charlist): array
    {
        return self::buildAddcslashesCharMask($charlist);
    }

    /**
     * stripcslashes() — unescape C-style sequences (php-src string.c php_stripcslashes).
     */
    public static function stripcslashes(string $string): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ('\\' !== $ch) {
                $out .= $ch;
                continue;
            }
            if ($i + 1 >= $len) {
                $out .= '\\';
                break;
            }
            $next = $string[++$i];
            switch ($next) {
                case 'n':
                    $out .= "\n";
                    break;
                case 'r':
                    $out .= "\r";
                    break;
                case 'a':
                    $out .= "\x07";
                    break;
                case 't':
                    $out .= "\t";
                    break;
                case 'v':
                    $out .= "\v";
                    break;
                case 'b':
                    $out .= "\x08";
                    break;
                case 'f':
                    $out .= "\f";
                    break;
                case 'e':
                    $out .= "\x1B";
                    break;
                case 'x':
                    if ($i + 2 < $len && self::isHexDigit($string[$i + 1]) && self::isHexDigit($string[$i + 2])) {
                        $out .= self::byteChr((int) \hexdec($string[$i + 1].$string[$i + 2]));
                        $i += 2;
                    } else {
                        $out .= 'x';
                    }
                    break;
                default:
                    if ($next >= '0' && $next <= '7') {
                        $oct = $next;
                        $digits = 1;
                        while ($digits < 3 && $i + 1 < $len && $string[$i + 1] >= '0' && $string[$i + 1] <= '7') {
                            $oct .= $string[++$i];
                            ++$digits;
                        }
                        $out .= self::byteChr((int) \octdec($oct));
                    } else {
                        $out .= $next;
                    }
                    break;
            }
        }

        return $out;
    }

    /**
     * substr_replace() — replace substring slice (php-src string.c php_substr_replace).
     */
    public static function substr_replace(string $string, string $replace, int $offset, ?int $length = null): string
    {
        $strLen = self::byteLength($string);
        if ($offset < 0) {
            $offset += $strLen;
            if ($offset < 0) {
                $offset = 0;
            }
        } elseif ($offset > $strLen) {
            $offset = $strLen;
        }
        $remain = $strLen - $offset;
        if (null === $length) {
            $length = $remain;
        } elseif ($length < 0) {
            $length += $remain;
            if ($length < 0) {
                $length = 0;
            }
        } elseif ($length > $remain) {
            $length = $remain;
        }

        return self::byteSlice($string, 0, $offset)
            .$replace
            .self::byteSlice($string, $offset + $length);
    }

    /** @return array<int, bool> */
    private static function buildAddcslashesCharMask(string $charlist): array
    {
        $expanded = self::expandAddcslashesCharlist($charlist);
        $mask = array_fill(0, 256, false);
        $len = self::byteLength($expanded);
        for ($i = 0; $i < $len; ++$i) {
            $c = self::byteOrd($expanded[$i]);
            if ($i + 3 < $len
                && '.' === $expanded[$i + 1]
                && '.' === $expanded[$i + 2]
                && self::byteOrd($expanded[$i + 3]) >= $c) {
                for ($ord = $c; $ord <= self::byteOrd($expanded[$i + 3]); ++$ord) {
                    $mask[$ord] = true;
                }
                $i += 3;
            } else {
                $mask[$c] = true;
            }
        }

        return $mask;
    }

    private static function expandAddcslashesCharlist(string $charlist): string
    {
        $out = '';
        $len = self::byteLength($charlist);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $charlist[$i];
            if ('\\' !== $ch || $i + 1 >= $len) {
                $out .= $ch;
                continue;
            }
            $next = $charlist[++$i];
            switch ($next) {
                case 'n':
                    $out .= "\n";
                    break;
                case 'r':
                    $out .= "\r";
                    break;
                case 'a':
                    $out .= "\x07";
                    break;
                case 't':
                    $out .= "\t";
                    break;
                case 'v':
                    $out .= "\v";
                    break;
                case 'b':
                    $out .= "\x08";
                    break;
                case 'f':
                    $out .= "\f";
                    break;
                case 'e':
                    $out .= "\x1B";
                    break;
                case 'x':
                    if ($i + 2 < $len && self::isHexDigit($charlist[$i + 1]) && self::isHexDigit($charlist[$i + 2])) {
                        $out .= self::byteChr((int) \hexdec($charlist[$i + 1].$charlist[$i + 2]));
                        $i += 2;
                    } else {
                        $out .= 'x';
                    }
                    break;
                default:
                    if ($next >= '0' && $next <= '7') {
                        $oct = $next;
                        $digits = 1;
                        while ($digits < 3 && $i + 1 < $len && $charlist[$i + 1] >= '0' && $charlist[$i + 1] <= '7') {
                            $oct .= $charlist[++$i];
                            ++$digits;
                        }
                        $out .= self::byteChr((int) \octdec($oct));
                    } else {
                        $out .= $next;
                    }
                    break;
            }
        }

        return $out;
    }

    private static function isHexDigit(string $ch): bool
    {
        return ($ch >= '0' && $ch <= '9') || ($ch >= 'a' && $ch <= 'f') || ($ch >= 'A' && $ch <= 'F');
    }

    public static function asciiLcfirst(string $string): string
    {
        if ('' === $string) {
            return '';
        }
        $ch = $string[0];
        $ord = self::byteOrd($ch);
        if ($ord >= 65 && $ord <= 90) {
            $ch = self::byteChr($ord + 32);
        }

        return $ch.self::byteSlice($string, 1);
    }

    public static function asciiUcfirst(string $string): string
    {
        if ('' === $string) {
            return '';
        }
        $ch = $string[0];
        $ord = self::byteOrd($ch);
        if ($ord >= 97 && $ord <= 122) {
            $ch = self::byteChr($ord - 32);
        }

        return $ch.self::byteSlice($string, 1);
    }

    /** ucwords() for byte strings — uppercase first letter after default whitespace (TRIM_DEFAULT). */
    public static function asciiUcwords(string $string): string
    {
        return self::asciiUcwordsEx($string, self::TRIM_DEFAULT);
    }

    /**
     * ucwords() with explicit separator mask (ext/standard/string.c php_ucwords_ex parity; ASCII letters).
     */
    public static function asciiUcwordsEx(string $string, string $separators): string
    {
        if ('' === $string) {
            return '';
        }
        $len = self::byteLength($string);
        $out = '';
        $atWordStart = true;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ($atWordStart) {
                $ord = self::byteOrd($ch);
                if ($ord >= 97 && $ord <= 122) {
                    $ch = self::byteChr($ord - 32);
                }
            }
            $out .= $ch;
            $atWordStart = str_contains($separators, $ch);
        }

        return $out;
    }

    public static function strReplace(string $search, string $replace, string $subject, ?int &$count = null): string
    {
        if ('' === $search) {
            if (null !== $count) {
                $count = 0;
            }

            return $subject;
        }
        $replacementCount = 0;
        $searchLen = self::byteLength($search);
        $out = '';
        $offset = 0;
        $len = self::byteLength($subject);
        while ($offset < $len) {
            $pos = self::findSubstring($subject, $search, $offset);
            if (false === $pos) {
                $out .= self::byteSlice($subject, $offset);
                break;
            }
            $out .= self::byteSlice($subject, $offset, $pos - $offset).$replace;
            $offset = $pos + $searchLen;
            ++$replacementCount;
        }
        if (null !== $count) {
            $count = $replacementCount;
        }

        return $out;
    }

    /** Case-insensitive str_replace() for two strings (ASCII fold; subset of PHP). */
    public static function strIreplace(string $search, string $replace, string $subject, ?int &$count = null): string
    {
        if ('' === $search) {
            if (null !== $count) {
                $count = 0;
            }

            return $subject;
        }
        $replacementCount = 0;
        $searchLen = self::byteLength($search);
        $out = '';
        $offset = 0;
        $len = self::byteLength($subject);
        while ($offset < $len) {
            $pos = self::findSubstringCaseInsensitive($subject, $search, $offset);
            if (false === $pos) {
                $out .= self::byteSlice($subject, $offset);
                break;
            }
            $out .= self::byteSlice($subject, $offset, $pos - $offset).$replace;
            $offset = $pos + $searchLen;
            ++$replacementCount;
        }
        if (null !== $count) {
            $count = $replacementCount;
        }

        return $out;
    }

    /**
     * str_replace() with array $search and array|string $replace (php-src string.c).
     *
     * @param list<string>        $search
     * @param list<string>|string $replace
     */
    public static function strReplaceMulti(array $search, array|string $replace, string $subject, ?int &$count = null): string
    {
        if ([] === $search) {
            if (null !== $count) {
                $count = 0;
            }

            return $subject;
        }
        $replaceIsArray = \is_array($replace);
        $replacementCount = 0;
        $out = '';
        $offset = 0;
        $len = self::byteLength($subject);
        $numSearch = \count($search);
        while ($offset < $len) {
            $bestPos = false;
            $bestIdx = -1;
            for ($i = 0; $i < $numSearch; ++$i) {
                $needle = $search[$i];
                if ('' === $needle) {
                    continue;
                }
                $pos = self::findSubstring($subject, $needle, $offset);
                if (false !== $pos && (false === $bestPos || $pos < $bestPos)) {
                    $bestPos = $pos;
                    $bestIdx = $i;
                }
            }
            if (false === $bestPos) {
                $out .= self::byteSlice($subject, $offset);
                break;
            }
            $needle = $search[$bestIdx];
            $out .= self::byteSlice($subject, $offset, $bestPos - $offset);
            $out .= $replaceIsArray ? ($replace[$bestIdx] ?? '') : $replace;
            $offset = $bestPos + self::byteLength($needle);
            ++$replacementCount;
        }
        if (null !== $count) {
            $count = $replacementCount;
        }

        return $out;
    }

    /**
     * Case-insensitive str_replace() with array $search and array|string $replace (php-src string.c).
     *
     * @param list<string>        $search
     * @param list<string>|string $replace
     */
    public static function strIreplaceMulti(array $search, array|string $replace, string $subject, ?int &$count = null): string
    {
        if ([] === $search) {
            if (null !== $count) {
                $count = 0;
            }

            return $subject;
        }
        $replaceIsArray = \is_array($replace);
        $replacementCount = 0;
        $out = '';
        $offset = 0;
        $len = self::byteLength($subject);
        $numSearch = \count($search);
        while ($offset < $len) {
            $bestPos = false;
            $bestIdx = -1;
            for ($i = 0; $i < $numSearch; ++$i) {
                $needle = $search[$i];
                if ('' === $needle) {
                    continue;
                }
                $pos = self::findSubstringCaseInsensitive($subject, $needle, $offset);
                if (false !== $pos && (false === $bestPos || $pos < $bestPos)) {
                    $bestPos = $pos;
                    $bestIdx = $i;
                }
            }
            if (false === $bestPos) {
                $out .= self::byteSlice($subject, $offset);
                break;
            }
            $needle = $search[$bestIdx];
            $out .= self::byteSlice($subject, $offset, $bestPos - $offset);
            $out .= $replaceIsArray ? ($replace[$bestIdx] ?? '') : $replace;
            $offset = $bestPos + self::byteLength($needle);
            ++$replacementCount;
        }
        if (null !== $count) {
            $count = $replacementCount;
        }

        return $out;
    }

    /**
     * strtr() two-string form — byte translation table (subset of PHP).
     */
    public static function strtr(string $string, string $from, string $to): string
    {
        if ('' === $from) {
            return $string;
        }
        $pairLen = min(self::byteLength($from), self::byteLength($to));
        $table = [];
        for ($i = 0; $i < 256; ++$i) {
            $table[$i] = \chr($i);
        }
        for ($i = 0; $i < $pairLen; ++$i) {
            $table[\ord($from[$i])] = $to[$i];
        }
        $len = self::byteLength($string);
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $out .= $table[\ord($string[$i])];
        }

        return $out;
    }

    /**
     * php-src ext/standard/string.c — empty replace_pairs key is skipped with E_WARNING (#26704).
     */
    public const STRTR_EMPTY_REPLACEMENT_WARNING = 'strtr(): Ignoring replacement of empty string';

    /**
     * strtr() replace_pairs HashTable form — php_strtr_array() parity (#28978).
     *
     * Keys are stringified up front (numeric keys via zend_long_to_str shape). Values use
     * convert_to_string only when a match is selected (zval_get_tmp_string) — unused nested
     * arrays must not warn. NestedJIT {@see StrtrArrayJitHelper} mirrors the lazy value cast.
     *
     * @see php/php-src ext/standard/string.c php_strtr_array()
     */
    public static function strtrArrayFromHashTable(string $string, HashTable $replacePairs, ?Frame $frame = null): string
    {
        $slen = self::byteLength($string);
        if (0 === $slen) {
            return '';
        }

        /** @var array<string, Variable> $byFrom */
        $byFrom = [];
        $minlen = $slen + 1;
        $maxlen = 0;
        $firstChars = [];
        $lengths = [];

        foreach ($replacePairs->exportKeyValuePairs(true) as $pair) {
            $from = self::coerceStrtrReplacePairOperand($pair[0], $frame);
            if ('' === $from) {
                self::warnStrtrEmptyReplacement($frame);
                continue;
            }
            $len = self::byteLength($from);
            if ($len > $slen) {
                continue;
            }
            if ($len < $minlen) {
                $minlen = $len;
            }
            if ($len > $maxlen) {
                $maxlen = $len;
            }
            $firstChars[\ord($from[0])] = true;
            $lengths[$len] = true;
            $byFrom[$from] = $pair[1];
        }

        if ($minlen > $maxlen || [] === $byFrom) {
            return $string;
        }

        if (1 === \count($byFrom)) {
            $fromKey = \array_key_first($byFrom);
            $from = \is_string($fromKey) ? $fromKey : (string) $fromKey;
            $to = self::coerceStrtrReplacePairOperand($byFrom[$fromKey], $frame);
            if (1 === self::byteLength($from) && 1 === self::byteLength($to)) {
                return self::strtr($string, $from, $to);
            }

            return self::strReplace($from, $to, $string);
        }

        $out = '';
        $pos = 0;
        $oldPos = 0;

        while ($pos <= $slen - $minlen) {
            if (isset($firstChars[\ord($string[$pos])])) {
                $tryLen = $maxlen;
                if ($tryLen > $slen - $pos) {
                    $tryLen = $slen - $pos;
                }
                while ($tryLen >= $minlen) {
                    if (isset($lengths[$tryLen])) {
                        $key = self::byteSlice($string, $pos, $tryLen);
                        if (isset($byFrom[$key])) {
                            $out .= self::byteSlice($string, $oldPos, $pos - $oldPos);
                            $out .= self::coerceStrtrReplacePairOperand($byFrom[$key], $frame);
                            $oldPos = $pos + $tryLen;
                            $pos = $oldPos - 1;
                            break;
                        }
                    }
                    --$tryLen;
                }
            }
            ++$pos;
        }

        if ('' !== $out) {
            $out .= self::byteSlice($string, $oldPos);

            return $out;
        }

        return $string;
    }

    /**
     * strtr() replace_pairs array form — longest-match substitution.
     *
     * @see php/php-src ext/standard/string.c php_strtr_array()
     *
     * @param array<string, string> $replacePairs
     */
    public static function strtrArray(string $string, array $replacePairs, ?Frame $frame = null): string
    {
        $slen = self::byteLength($string);
        if (0 === $slen) {
            return '';
        }
        if ([] === $replacePairs) {
            return $string;
        }

        $pairs = [];
        foreach ($replacePairs as $from => $to) {
            if (!\is_string($from)) {
                $from = (string) $from;
            }
            if (!\is_string($to)) {
                $to = (string) $to;
            }
            if ('' === $from) {
                // php-src php_strtr_array() — php_error_docref(E_WARNING) then skip (#26704).
                self::warnStrtrEmptyReplacement($frame);
                continue;
            }
            if (self::byteLength($from) > $slen) {
                continue;
            }
            $pairs[$from] = $to;
        }

        if ([] === $pairs) {
            return $string;
        }

        if (1 === \count($pairs)) {
            $from = \array_key_first($pairs);
            $to = $pairs[$from];
            if (!\is_string($from)) {
                $from = (string) $from;
            }
            if (!\is_string($to)) {
                $to = (string) $to;
            }
            // Three-arg strtr() only maps 1-byte→1-byte; empty/multi-byte $to need str_replace
            // (#28978 nested "Array", #28976 empty deletion).
            if (1 === self::byteLength($from) && 1 === self::byteLength($to)) {
                return self::strtr($string, $from, $to);
            }

            return self::strReplace($from, $to, $string);
        }

        return self::strtrArrayLongestMatch($string, $pairs);
    }

    /**
     * php-src php_strtr_array() empty from-key — E_WARNING then skip (#26704).
     *
     * VM: ErrorReporter via call frame. JIT/AOT helpers: compiler_language_warning().
     */
    public static function warnStrtrEmptyReplacement(?Frame $frame): void
    {
        if (null !== $frame && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                self::STRTR_EMPTY_REPLACEMENT_WARNING,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame,
                $frame->callSiteLine
            );

            return;
        }

        if (\function_exists('compiler_language_warning')) {
            compiler_language_warning(self::STRTR_EMPTY_REPLACEMENT_WARNING);
        }
    }

    /**
     * @param list<array{0: string, 1: string}> $tupleList
     */
    private static function strtrArrayFromPairTuples(string $string, array $tupleList, ?Frame $frame = null): string
    {
        $pairs = [];
        foreach ($tupleList as [$from, $to]) {
            $pairs[$from] = $to;
        }

        return self::strtrArray($string, $pairs, $frame);
    }

    /**
     * @param array<string, string> $pairs
     */
    private static function strtrArrayLongestMatch(string $string, array $pairs): string
    {
        $slen = self::byteLength($string);
        $minlen = $slen + 1;
        $maxlen = 0;
        $firstChars = [];
        $lengths = [];

        foreach ($pairs as $from => $to) {
            // PHP array keys coerce numeric-strings to int (#28977); stringify before byteLength.
            if (!\is_string($from)) {
                $from = (string) $from;
            }
            $len = self::byteLength($from);
            if ($len < $minlen) {
                $minlen = $len;
            }
            if ($len > $maxlen) {
                $maxlen = $len;
            }
            $firstChars[\ord($from[0])] = true;
            $lengths[$len] = true;
        }

        if ($minlen > $maxlen) {
            return $string;
        }

        $out = '';
        $pos = 0;
        $oldPos = 0;

        while ($pos <= $slen - $minlen) {
            if (isset($firstChars[\ord($string[$pos])])) {
                $tryLen = $maxlen;
                if ($tryLen > $slen - $pos) {
                    $tryLen = $slen - $pos;
                }
                while ($tryLen >= $minlen) {
                    if (isset($lengths[$tryLen])) {
                        $key = self::byteSlice($string, $pos, $tryLen);
                        if (isset($pairs[$key])) {
                            $out .= self::byteSlice($string, $oldPos, $pos - $oldPos);
                            $out .= $pairs[$key];
                            $oldPos = $pos + $tryLen;
                            $pos = $oldPos - 1;
                            break;
                        }
                    }
                    --$tryLen;
                }
            }
            ++$pos;
        }

        if ('' !== $out) {
            $out .= self::byteSlice($string, $oldPos);

            return $out;
        }

        return $string;
    }

    public static function nl2br(string $string, bool $useXhtml = true): string
    {
        $br = $useXhtml ? '<br />' : '<br>';
        $len = self::byteLength($string);
        $replCount = 0;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ("\r" === $ch) {
                if ($i + 1 < $len && "\n" === $string[$i + 1]) {
                    ++$i;
                }
                ++$replCount;
            } elseif ("\n" === $ch) {
                if ($i + 1 < $len && "\r" === $string[$i + 1]) {
                    ++$i;
                }
                ++$replCount;
            }
        }
        if (0 === $replCount) {
            return $string;
        }

        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ("\r" === $ch || "\n" === $ch) {
                $out .= $br;
                if ($i + 1 < $len && (
                    ("\r" === $ch && "\n" === $string[$i + 1])
                    || ("\n" === $ch && "\r" === $string[$i + 1])
                )) {
                    $out .= $ch;
                    ++$i;
                    $ch = $string[$i];
                }
                $out .= $ch;
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    /**
     * @return int|false
     */
    public static function strpos(string $haystack, string $needle, int $offset = 0)
    {
        if ('' === $needle) {
            return self::normalizeContainedStringOffset(
                self::byteLength($haystack),
                $offset,
                'strpos'
            );
        }
        $offset = self::normalizeContainedStringOffset(
            self::byteLength($haystack),
            $offset,
            'strpos'
        );
        $pos = self::findSubstring($haystack, $needle, $offset);

        return false === $pos ? false : $pos;
    }

    /**
     * @return string|false
     */
    public static function strstr(string $haystack, string $needle, bool $beforeNeedle = false)
    {
        if ('' === $needle) {
            return $beforeNeedle ? '' : $haystack;
        }
        $pos = self::findSubstring($haystack, $needle, 0);
        if (false === $pos) {
            return false;
        }
        if ($beforeNeedle) {
            return self::byteSlice($haystack, 0, $pos);
        }

        return self::byteSlice($haystack, $pos);
    }

    /**
     * @return string|false
     */
    public static function strrchr(string $haystack, string $needle)
    {
        if ('' === $needle) {
            return false;
        }
        $pos = self::strrpos($haystack, $needle[0], 0);
        if (false === $pos) {
            return false;
        }

        return self::byteSlice($haystack, $pos);
    }

    /**
     * @return string|false
     */
    public static function stristr(string $haystack, string $needle, bool $beforeNeedle = false)
    {
        if ('' === $needle) {
            return $beforeNeedle ? '' : $haystack;
        }
        $pos = self::findSubstringCaseInsensitive($haystack, $needle, 0);
        if (false === $pos) {
            return false;
        }
        if ($beforeNeedle) {
            return self::byteSlice($haystack, 0, $pos);
        }

        return self::byteSlice($haystack, $pos);
    }

    /**
     * str_word_count() — count words or return word list (ASCII subset of PHP; issue #2382).
     *
     * @return int|list<string>|array<int, string>
     */
    public static function str_word_count(string $string, int $format = 0, string $chars = ''): int|array
    {
        if ($format < 0 || $format > 2) {
            throw new \ValueError('str_word_count(): Argument #2 ($format) must be a valid format value');
        }
        $extra = self::strWordCountExtraMask($chars);
        $len = self::byteLength($string);
        $words = [];
        $positions = [];
        $count = 0;
        $inWord = false;
        $wordStart = 0;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if (self::isStrWordChar($ch, $inWord, $extra)) {
                if (!$inWord) {
                    $wordStart = $i;
                    $inWord = true;
                    ++$count;
                }
            } elseif ($inWord) {
                if (1 === $format || 2 === $format) {
                    $word = self::byteSlice($string, $wordStart, $i - $wordStart);
                    if (1 === $format) {
                        $words[] = $word;
                    } else {
                        $positions[$wordStart] = $word;
                    }
                }
                $inWord = false;
            }
        }
        if ($inWord && (1 === $format || 2 === $format)) {
            $word = self::byteSlice($string, $wordStart);
            if (1 === $format) {
                $words[] = $word;
            } else {
                $positions[$wordStart] = $word;
            }
        }
        if (0 === $format) {
            return $count;
        }
        if (1 === $format) {
            return $words;
        }

        return $positions;
    }

    /**
     * @return array<int, bool>
     */
    private static function strWordCountExtraMask(string $chars): array
    {
        if ('' === $chars) {
            return [];
        }
        $mask = [];
        $clen = self::byteLength($chars);
        for ($i = 0; $i < $clen; ++$i) {
            $mask[self::byteOrd($chars[$i])] = true;
        }

        return $mask;
    }

    /**
     * @param array<int, bool> $extra
     */
    private static function isStrWordLetter(string $ch): bool
    {
        $ord = self::byteOrd($ch);

        return ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122);
    }

    private static function isStrWordChar(string $ch, bool $inWord, array $extra): bool
    {
        $ord = self::byteOrd($ch);
        if (isset($extra[$ord])) {
            return true;
        }
        if (self::isStrWordLetter($ch)) {
            return true;
        }

        return $inWord && (39 === $ord || 45 === $ord);
    }

    /**
     * Count non-overlapping occurrences of $needle in $haystack (byte-safe subset of PHP).
     */
    public static function substr_count(
        string $haystack,
        string $needle,
        int $offset = 0,
        ?int $length = null
    ): int {
        if ('' === $needle) {
            self::rejectEmptyBuiltinStringArg($needle, 'substr_count', 1, 'needle', true);
        }
        $hayLen = self::byteLength($haystack);
        $needleLen = self::byteLength($needle);
        $searchLen = $hayLen;
        if (0 !== $offset) {
            if ($offset < 0) {
                $offset += $hayLen;
            }
            if ($offset < 0 || $offset > $hayLen) {
                throw new \ValueError('substr_count(): Argument #3 ($offset) must be contained in argument #1 ($haystack)');
            }
            $searchLen = $hayLen - $offset;
        }
        if (null !== $length) {
            if ($length < 0) {
                $length += $searchLen;
            }
            if ($length < 0 || $length > $searchLen) {
                throw new \ValueError('substr_count(): Argument #4 ($length) must be contained in argument #1 ($haystack)');
            }
            $searchLen = $length;
        }
        $end = $offset + $searchLen;
        $limit = $end - $needleLen;
        if ($limit < $offset) {
            return 0;
        }
        $count = 0;
        $pos = $offset;
        while ($pos <= $limit) {
            $found = self::findSubstring($haystack, $needle, $pos);
            if (false === $found || $found > $limit) {
                break;
            }
            ++$count;
            $pos = $found + $needleLen;
        }

        return $count;
    }

    /**
     * count_chars() — byte-frequency histogram (PHP 8 modes 0–4; ext/standard/string.c).
     *
     * @return array<int, int>|string
     */
    public static function count_chars(string $string, int $mode = 0): array|string
    {
        if ($mode < 0 || $mode > 4) {
            throw new \ValueError('count_chars(): Argument #2 ($mode) must be between 0 and 4 (inclusive)');
        }
        $counts = array_fill(0, 256, 0);
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            ++$counts[self::byteOrd($string[$i])];
        }
        if (3 === $mode || 4 === $mode) {
            $out = '';
            for ($byte = 0; $byte < 256; ++$byte) {
                if ((3 === $mode && $counts[$byte] > 0) || (4 === $mode && 0 === $counts[$byte])) {
                    $out .= self::byteChr($byte);
                }
            }

            return $out;
        }
        $result = [];
        for ($byte = 0; $byte < 256; ++$byte) {
            if (0 === $mode) {
                $result[$byte] = $counts[$byte];
            } elseif (1 === $mode && $counts[$byte] > 0) {
                $result[$byte] = $counts[$byte];
            } elseif (2 === $mode && 0 === $counts[$byte]) {
                $result[$byte] = 0;
            }
        }

        return $result;
    }

    /**
     * @return int|false
     */
    public static function stripos(string $haystack, string $needle, int $offset = 0)
    {
        if ('' === $needle) {
            return self::normalizeContainedStringOffset(
                self::byteLength($haystack),
                $offset,
                'stripos'
            );
        }
        $offset = self::normalizeContainedStringOffset(
            self::byteLength($haystack),
            $offset,
            'stripos'
        );
        $pos = self::findSubstringCaseInsensitive($haystack, $needle, $offset);

        return false === $pos ? false : $pos;
    }

    /**
     * @return int|false
     */
    public static function strrpos(string $haystack, string $needle, int $offset = 0)
    {
        $hayLen = self::byteLength($haystack);
        $minStart = 0;
        $maxStart = null;
        $suffixEnd = $hayLen + $offset;
        if ($suffixEnd < $hayLen) {
            if ($suffixEnd < 0) {
                throw new \ValueError(sprintf(
                    'strrpos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'
                ));
            }
            $maxStart = $suffixEnd;
        } else {
            $minStart = $offset;
        }
        if ('' === $needle) {
            return null !== $maxStart ? $maxStart : $hayLen;
        }
        $pos = self::findRSubstring($haystack, $needle, $minStart, $maxStart);

        return false === $pos ? false : $pos;
    }

    /**
     * @return int|false
     */
    public static function strripos(string $haystack, string $needle, int $offset = 0)
    {
        $hayLen = self::byteLength($haystack);
        $minStart = 0;
        $maxStart = null;
        $suffixEnd = $hayLen + $offset;
        if ($suffixEnd < $hayLen) {
            if ($suffixEnd < 0) {
                throw new \ValueError(sprintf(
                    'strripos(): Argument #3 ($offset) must be contained in argument #1 ($haystack)'
                ));
            }
            $maxStart = $suffixEnd;
        } else {
            $minStart = $offset;
        }
        if ('' === $needle) {
            return null !== $maxStart ? $maxStart : $hayLen;
        }
        $pos = self::findRSubstringCaseInsensitive($haystack, $needle, $minStart, $maxStart);

        return false === $pos ? false : $pos;
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        $nlen = self::byteLength($needle);
        if (0 === $nlen) {
            return true;
        }
        $hlen = self::byteLength($haystack);
        if ($nlen > $hlen) {
            return false;
        }

        return self::compareBytes($haystack, $needle, $nlen);
    }

    public static function endsWith(string $haystack, string $needle): bool
    {
        $nlen = self::byteLength($needle);
        if (0 === $nlen) {
            return true;
        }
        $hlen = self::byteLength($haystack);
        if ($nlen > $hlen) {
            return false;
        }

        return self::compareBytes($haystack, $needle, $nlen, $hlen - $nlen);
    }

    private static function charInMask(string $ch, string $mask): bool
    {
        $maskLen = self::byteLength($mask);
        for ($i = 0; $i < $maskLen; ++$i) {
            if ($mask[$i] === $ch) {
                return true;
            }
        }

        return false;
    }

    private static function compareBytes(string $haystack, string $needle, int $length, int $hayOffset = 0): bool
    {
        for ($i = 0; $i < $length; ++$i) {
            if ($haystack[$hayOffset + $i] !== $needle[$i]) {
                return false;
            }
        }

        return true;
    }

    private static function compareBytesCaseInsensitive(string $haystack, string $needle, int $length, int $hayOffset = 0): bool
    {
        for ($i = 0; $i < $length; ++$i) {
            if (self::asciiLowerByte($haystack[$hayOffset + $i]) !== self::asciiLowerByte($needle[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function asciiLowerByte(string $byte): string
    {
        $ord = self::byteOrd($byte);
        if ($ord >= 65 && $ord <= 90) {
            return self::byteChr($ord + 32);
        }

        return $byte;
    }

    /**
     * PHP 8+ strpos/stripos offset: negative counts from end; must lie in [-hayLen, hayLen].
     *
     * @see php/php-src ext/standard/string.c php_strpos()
     */
    private static function normalizeContainedStringOffset(
        int $hayLen,
        int $offset,
        string $functionName,
        int $argNum = 3
    ): int {
        if ($offset < 0) {
            $offset += $hayLen;
        }
        if ($offset < 0 || $offset > $hayLen) {
            throw new \ValueError(sprintf(
                '%s(): Argument #%d ($offset) must be contained in argument #1 ($haystack)',
                $functionName,
                $argNum
            ));
        }

        return $offset;
    }

    /**
     * @return int|false
     */
    private static function findSubstring(string $haystack, string $needle, int $offset)
    {
        $hayLen = self::byteLength($haystack);
        $needleLen = self::byteLength($needle);
        if (0 === $needleLen) {
            return false;
        }
        if ($offset >= $hayLen) {
            return false;
        }
        $limit = $hayLen - $needleLen;
        for ($i = $offset; $i <= $limit; ++$i) {
            if (self::compareBytes($haystack, $needle, $needleLen, $i)) {
                return $i;
            }
        }

        return false;
    }

    /**
     * @return int|false
     */
    private static function findSubstringCaseInsensitive(string $haystack, string $needle, int $offset)
    {
        $hayLen = self::byteLength($haystack);
        $needleLen = self::byteLength($needle);
        if (0 === $needleLen) {
            return false;
        }
        if ($offset >= $hayLen) {
            return false;
        }
        $limit = $hayLen - $needleLen;
        for ($i = $offset; $i <= $limit; ++$i) {
            if (self::compareBytesCaseInsensitive($haystack, $needle, $needleLen, $i)) {
                return $i;
            }
        }

        return false;
    }

    /**
     * @return int|false
     */
    private static function findRSubstring(
        string $haystack,
        string $needle,
        int $offset,
        ?int $maxStart = null
    ) {
        $hayLen = self::byteLength($haystack);
        $needleLen = self::byteLength($needle);
        if (0 === $needleLen) {
            return false;
        }
        if ($offset >= $hayLen) {
            return false;
        }
        $limit = $hayLen - $needleLen;
        if (null !== $maxStart && $maxStart < $limit) {
            $limit = $maxStart;
        }
        if ($limit < $offset) {
            return false;
        }
        $last = false;
        for ($i = $offset; $i <= $limit; ++$i) {
            if (self::compareBytes($haystack, $needle, $needleLen, $i)) {
                $last = $i;
            }
        }

        return $last;
    }

    /**
     * @return int|false
     */
    private static function findRSubstringCaseInsensitive(
        string $haystack,
        string $needle,
        int $offset,
        ?int $maxStart = null
    ) {
        $hayLen = self::byteLength($haystack);
        $needleLen = self::byteLength($needle);
        if (0 === $needleLen) {
            return false;
        }
        if ($offset >= $hayLen) {
            return false;
        }
        $limit = $hayLen - $needleLen;
        if (null !== $maxStart && $maxStart < $limit) {
            $limit = $maxStart;
        }
        if ($limit < $offset) {
            return false;
        }
        $last = false;
        for ($i = $offset; $i <= $limit; ++$i) {
            if (self::compareBytesCaseInsensitive($haystack, $needle, $needleLen, $i)) {
                $last = $i;
            }
        }

        return $last;
    }

    private static function asciiCaseTransform(string $string, bool $toLower): string
    {
        $out = '';
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            $ord = self::byteOrd($ch);
            if ($toLower) {
                if ($ord >= 65 && $ord <= 90) {
                    $ch = self::byteChr($ord + 32);
                }
            } elseif ($ord >= 97 && $ord <= 122) {
                $ch = self::byteChr($ord - 32);
            }
            $out .= $ch;
        }

        return $out;
    }

    /** php-src basename.c / dir.c — backslash is a separator only on Windows (#10766). */
    private static function isPathSeparatorByte(string $byte): bool
    {
        if ('/' === $byte) {
            return true;
        }

        return '\\' === $byte && 'Windows' === \PHP_OS_FAMILY;
    }

    public static function dirname(string $path, int $levels = 1): string
    {
        if ($levels < 1) {
            throw new \ValueError('dirname(): Argument #2 ($levels) must be greater than or equal to 1');
        }
        $result = $path;
        for ($i = 0; $i < $levels; ++$i) {
            $result = self::dirnameOnce($result);
        }

        return $result;
    }

    private static function dirnameOnce(string $path): string
    {
        $len = self::byteLength($path);
        if (0 === $len) {
            return '';
        }
        $end = $len;
        while ($end > 0 && self::isPathSeparatorByte($path[$end - 1])) {
            --$end;
        }
        if (0 === $end) {
            return '/' === $path[0] ? '/' : '.';
        }

        // php-src zend_dirname(): only path separators after "://" count; wrapper roots
        // like phar://archive.phar/ dirname to scheme + ":" (not "phar:/").
        $schemeSep = strpos($path, '://');
        $minSepIndex = 0;
        if (false !== $schemeSep) {
            $minSepIndex = $schemeSep + 3;
        }

        $last = -1;
        for ($i = $end - 1; $i >= $minSepIndex; --$i) {
            if (self::isPathSeparatorByte($path[$i])) {
                $last = $i;
                break;
            }
        }
        if (-1 === $last) {
            if (false !== $schemeSep) {
                return self::byteSlice($path, 0, $schemeSep + 1);
            }

            return '.';
        }
        if (0 === $last) {
            return '/' === $path[0] ? '/' : '.';
        }

        return self::byteSlice($path, 0, $last);
    }

    public static function basename(string $path, string $suffix = ''): string
    {
        $len = self::byteLength($path);
        if (0 === $len) {
            return self::stripBasenameSuffix('', $suffix);
        }
        $end = $len;
        while ($end > 0 && self::isPathSeparatorByte($path[$end - 1])) {
            --$end;
        }
        if (0 === $end) {
            return self::stripBasenameSuffix('', $suffix);
        }
        for ($i = $end - 1; $i >= 0; --$i) {
            if (self::isPathSeparatorByte($path[$i])) {
                return self::stripBasenameSuffix(
                    self::byteSlice($path, $i + 1, $end - $i - 1),
                    $suffix
                );
            }
        }

        return self::stripBasenameSuffix(self::byteSlice($path, 0, $end), $suffix);
    }

    private static function stripBasenameSuffix(string $base, string $suffix): string
    {
        $suffixLen = self::byteLength($suffix);
        if ($suffixLen > 0) {
            $baseLen = self::byteLength($base);
            // php-src basename.c: strip only when basename is strictly longer than suffix.
            if ($baseLen > $suffixLen
                && self::compareBytes($base, $suffix, $suffixLen, $baseLen - $suffixLen)) {
                return self::byteSlice($base, 0, $baseLen - $suffixLen);
            }
        }

        return $base;
    }

    /**
     * @return string|false
     */
    public static function realpath(string $path) {
        if ('' === $path) {
            $path = '.';
        }
        if (str_contains($path, "\0")) {
            return false;
        }
        if (VmStatNative::available()) {
            $resolved = VmStatNative::realpath($path);
        } else {
            $resolved = self::realpathWithoutLibc($path);
        }
        if (false !== $resolved) {
            VmRealpathCache::record($path, $resolved);
        }

        return $resolved;
    }

    /**
     * getcwd + normalizePath when libc FFI is unavailable (no symlink resolution).
     *
     * @return string|false
     */
    private static function realpathWithoutLibc(string $path): string|false
    {
        $absolute = '' !== $path && ('/' === $path[0] || '\\' === $path[0]);
        if (!$absolute) {
            $cwd = VmGetcwdNative::resolve();
            if (false === $cwd || '' === $cwd) {
                return false;
            }
            $path = self::normalizePath($cwd.'/'.$path);
        } else {
            $path = self::normalizePath($path);
        }
        if (!file_exists($path)) {
            return false;
        }

        return $path;
    }

    public static function normalizePath(string $path): string
    {
        $absolute = '' !== $path && ('/' === $path[0] || '\\' === $path[0]);
        $parts = [];
        $len = self::byteLength($path);
        $segment = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $path[$i];
            if ('/' === $ch || '\\' === $ch) {
                if ('' !== $segment) {
                    if ('..' === $segment) {
                        array_pop($parts);
                    } elseif ('.' !== $segment) {
                        $parts[] = $segment;
                    }
                    $segment = '';
                }

                continue;
            }
            $segment .= $ch;
        }
        if ('' !== $segment) {
            if ('..' === $segment) {
                array_pop($parts);
            } elseif ('.' !== $segment) {
                $parts[] = $segment;
            }
        }
        $joined = implode('/', $parts);
        if ($absolute) {
            return '' === $joined ? '/' : '/'.$joined;
        }

        return '' === $joined ? '.' : $joined;
    }

    /**
     * @return array|string
     */
    public static function pathinfo(string $path, int $flags = 15)
    {
        $dirname = self::dirname($path);
        $basename = self::basename($path);
        $lastDot = self::pathLastDotInBasename($basename);
        $extension = -1 === $lastDot
            ? ''
            : self::byteSlice($basename, $lastDot + 1, self::byteLength($basename) - $lastDot - 1);
        $filename = -1 === $lastDot
            ? $basename
            : self::byteSlice($basename, 0, $lastDot);

        $mask = $flags & 15;
        // php-src php_pathinfo(): options==0 → empty string (not empty array). #24941
        if (0 === $mask) {
            return '';
        }

        $parts = [];
        if ($mask & 1) {
            if (15 === $mask) {
                if ('' !== $dirname) {
                    $parts['dirname'] = $dirname;
                }
            } else {
                $parts['dirname'] = $dirname;
            }
        }
        if ($mask & 2) {
            $parts['basename'] = $basename;
        }
        if ($mask & 4) {
            if (15 === $mask) {
                if (-1 !== $lastDot) {
                    $parts['extension'] = $extension;
                }
            } else {
                $parts['extension'] = $extension;
            }
        }
        if ($mask & 8) {
            $parts['filename'] = $filename;
        }

        if (1 === \count($parts)) {
            return reset($parts);
        }

        // php-src php_pathinfo(): multiple bits (not PATHINFO_ALL) → single string by priority.
        if (15 !== $mask) {
            if ($mask & 1) {
                return $dirname;
            }
            if ($mask & 2) {
                return $basename;
            }
            if ($mask & 4) {
                return $extension;
            }

            return $filename;
        }

        return $parts;
    }

    /** Last dot index in basename (php-src zend_memrchr); -1 when absent. */
    private static function pathLastDotInBasename(string $basename): int
    {
        $len = self::byteLength($basename);
        for ($i = $len - 1; $i >= 0; --$i) {
            if ('.' === $basename[$i]) {
                return $i;
            }
        }

        return -1;
    }

    public static function pathExtension(string $path): string
    {
        $base = self::basename($path);
        $lastDot = self::pathLastDotInBasename($base);
        if (-1 === $lastDot) {
            return '';
        }

        return self::byteSlice($base, $lastDot + 1, self::byteLength($base) - $lastDot - 1);
    }

    public static function pathFilename(string $path): string
    {
        $base = self::basename($path);
        $lastDot = self::pathLastDotInBasename($base);
        if (-1 === $lastDot) {
            return $base;
        }

        return self::byteSlice($base, 0, $lastDot);
    }

    /** Source string for strtok() continuation (ext/standard/string.c; issue #3201). */
    private static ?string $strtokString = null;

    private static int $strtokLast = 0;

    /**
     * strtok() — tokenize with re-entrant static state (php-src ext/standard/string.c).
     *
     * @return string|false
     */
    public static function strtok(?string $str, ?string $tok = null): string|false
    {
        if (null !== $tok) {
            // php-src: second parameter provided — (re)init; null haystack leaves no buffer (#5515).
            if (null === $str) {
                self::strtokReset();

                return false;
            }
            self::$strtokString = $str;
            self::$strtokLast = 0;
            $delimiter = $tok;
        } elseif (null === $str) {
            if (null === self::$strtokString) {
                return false;
            }
            // One-arg strtok(null): tok = str (null); php-src uses null delimiter (remainder token).
            $delimiter = '';
        } else {
            if (null === self::$strtokString) {
                return false;
            }
            // One-arg strtok($token): continue with delimiter string (php-src tok = str).
            $delimiter = $str;
        }

        $len = self::byteLength(self::$strtokString);
        $p = self::$strtokLast;
        if ($p >= $len) {
            self::strtokReset();

            return false;
        }

        $table = array_fill(0, 256, false);
        $delLen = self::byteLength($delimiter);
        for ($i = 0; $i < $delLen; ++$i) {
            $table[self::byteOrd($delimiter[$i])] = true;
        }

        $skipped = 0;
        while ($p < $len && $table[self::byteOrd(self::$strtokString[$p])]) {
            ++$p;
            ++$skipped;
            if ($p >= $len) {
                self::strtokReset();

                return false;
            }
        }

        while (++$p < $len) {
            if ($table[self::byteOrd(self::$strtokString[$p])]) {
                $token = self::byteSlice(
                    self::$strtokString,
                    self::$strtokLast + $skipped,
                    $p - self::$strtokLast - $skipped
                );
                self::$strtokLast = $p + 1;

                return $token;
            }
        }

        if ($p > self::$strtokLast) {
            $token = self::byteSlice(
                self::$strtokString,
                self::$strtokLast + $skipped,
                $p - self::$strtokLast - $skipped
            );
            self::strtokReset();

            return $token;
        }

        self::strtokReset();

        return false;
    }

    /** @internal JIT/AOT StrtokJitHelper bridge (#9812) */
    public static function strtokResetState(): void
    {
        self::strtokReset();
    }

    /** @internal JIT/AOT StrtokJitHelper bridge (#9812) */
    public static function strtokInitState(string $str): void
    {
        self::$strtokString = $str;
        self::$strtokLast = 0;
    }

    private static function strtokReset(): void
    {
        self::$strtokString = null;
        self::$strtokLast = 0;
    }

    private static function byteOrd(string $byte): int
    {
        return ord($byte);
    }

    private static function byteChr(int $code): string
    {
        return chr($code);
    }
}
