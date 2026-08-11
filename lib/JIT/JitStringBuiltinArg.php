<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmNullStringParamDeprecation;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\Builtin\Type\Object_ as JitObjectType;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Lower string builtin operands with Z_PARAM_STR parity (#5780, ext/standard/string.c).
 *
 * Runtime strictness hook (#7361): future php-compiler-strict skips need static proof
 * before omitting enum-case / object rejection blocks here; default stays php-src-strict.
 */
final class JitStringBuiltinArg
{
    /** Z_PARAM_STR null coerces outside caller strict_types (#19161). */
    public static function requiresForwardProfileStrictStringNull(): bool
    {
        return VmString::requiresForwardProfileStrictStringNull();
    }

    /** Z_PARAM_STR typed operands — null TypeError on 8.4 forward profile (#18840, #18980, #19222). */
    public static function requiresZparamStrStrictNullOnForwardProfile(): bool
    {
        return VmString::requiresZparamStrStrictNullOnForwardProfile();
    }

    /**
     * Z_PARAM_STR with caller strict_types parity (#12276, #12274).
     */
    public static function lowerStrictOrCoercible(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null,
        bool $rejectNullOnForwardProfile = true
    ): Value {
        if ($context->callerStrictTypes) {
            if (Variable::TYPE_VALUE === $arg->type || Variable::TYPE_OBJECT === $arg->type) {
                return self::lowerRequiredString($context, $arg, $function, $argIndex, $paramName);
            }
            if (Variable::TYPE_STRING !== $arg->type) {
                JitNativeString::ensureInsertBlock($context);
                self::emitTypeErrorAndAbort(
                    $context,
                    $function,
                    $argIndex,
                    $paramName,
                    JitOperandTypeLabel::givenLabel($context, $arg),
                    $expectedType
                );

                return self::unreachableStringPtr($context);
            }

            return JitStringArg::lower($context, $arg, "{$function}() argument #" . ($argIndex + 1));
        }

        return self::lower(
            $context,
            $arg,
            $function,
            $argIndex,
            $paramName,
            $expectedType,
            $arrayExpectedType,
            $rejectNullOnForwardProfile
        );
    }

    /**
     * Z_PARAM_STR — null TypeError on 8.4 forward profile (#18837, #18838, ext/standard/string.c).
     */
    public static function lowerZparamStr(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null
    ): Value {
        return self::lower(
            $context,
            $arg,
            $function,
            $argIndex,
            $paramName,
            $expectedType,
            $arrayExpectedType,
            false,
            true
        );
    }

    /**
     * Soft-null string args — coerce+deprecate on forward profile (not Z_PARAM_STR TypeError).
     *
     * str_repeat/str_shuffle/ucfirst/lcfirst/ucwords soft-null (#24598, reverts #24213/#20080).
     * str_increment/str_decrement soft-null then empty ValueError (#26264, reverts #21005 TypeError).
     * strlen/strtolower/strtoupper/strrev (#20007), md5/sha1/crc32/bin2hex/hash($data)/hash_hmac($data)/hash_hmac($key)/hash_update($data) (#21181, #21209, #21557),
     * hash()/hash_hmac()/hash_file()/hash_init() $algo soft-null (#21490/#21572, reverts #20304 TypeError),
     * version_compare($version1/$version2) soft-null (#21556, reverts #20254 TypeError; ext/standard/versioning.c),
     * getimagesizefromstring($string) soft-null (#21492, reverts #20353 TypeError; ext/standard/image.c),
     * stripslashes/addcslashes/stripcslashes/quotemeta (+ decode siblings) (#21180), str_contains/str_starts_with/str_ends_with (#21187),
     * base64 encode/decode, url encode/decode, parse_url (#21188),
     * mb_strlen/mb_substr/mb_strpos + iconv/iconv_* string inputs (#21197),
     * mb_strtolower/mb_strtoupper/mb_convert_case/mb_strstr/mb_stristr/mb_strrchr/mb_stripos/mb_strrpos/mb_strripos (#21313),
     * mb_strcut/mb_strimwidth/mb_encode_mimeheader (#21430),
     * mb_scrub/mb_detect_encoding (#21516, reverts #21061/#20225 TypeError),
     * mb_trim/mb_ltrim/mb_rtrim/mb_ucfirst/mb_lcfirst/mb_str_pad (#24176, reverts #17132/#19433/#19184),
     * preg_match/preg_split/preg_match_all $subject (#21198, #21318), and substr/strpos/strstr/explode (#21189),
     * substr_count/substr_replace haystack (#21196), ord() (#21222),
     * chunk_split/str_pad/wordwrap/soundex/metaphone/strcmp/strcasecmp (#21190),
     * levenshtein/similar_text/strcspn/strspn/strtok($string) (#21195),
     * strncmp/strncasecmp/strnatcmp/strnatcasecmp/strcoll (#21317),
     * substr_compare haystack/needle (#21515, reverts #20164 TypeError),
     * json_decode/json_validate $json, unserialize $data (#21223).
     * parse_ini_string $ini_string soft-null (#21431, reverts #18658).
     * parse_str $string soft-null (#21480, reverts #21380 TypeError).
     * trigger_error/user_error $message soft-null (#21480, reverts #21035 TypeError).
     * introspection name args (function_exists/class_exists/defined/…) (#21281).
     * error_log($message), gethostbyname($hostname), dns_get_record($hostname) soft-null (#24965, re-#24178, reverts #23858).
     * hex2bin/convert_uuencode/convert_uudecode/sscanf($string/$format), pack($values) soft-null (#21209/#21420/#21521).
     * unpack($string) soft-null (#21246).
     * escapeshellarg/escapeshellcmd soft-null (#21221, re-#19333).
     * date/gmdate $format and strtotime $datetime soft-null (#21208, reverts #19651).
     * idate $format soft-null (#21491, reverts #20227 TypeError).
     * date_parse $datetime soft-null (#24862, reverts #20227 TypeError; ext/date/php_date.c).
     * DateTime::format()/date_format() $format soft-null (#21536, reverts #20693 TypeError).
     * timezone_open/DateTimeZone/date_default_timezone_set timezone id soft-null (#21369, ext/date/php_date.stub.php).
     * password_verify/password_needs_rehash/password_hash/password_get_info string operands soft-null (#21314/#21210/#21537; hash_equals stays TypeError).
     * hash_pbkdf2($algo/$password/$salt) and hash_hkdf($algo/$key/$info/$salt) soft-null (#21319, reverts #20659/#21079).
     * str_rot13/crypt/uniqid/gzcompress soft-null (#21280).
     * hebrev($string) soft-null (#21421, ext/standard/string.c).
     * zlib one-shot $data (gzdeflate/gzinflate/gzdecode/gzuncompress/gzcompress/gzencode) soft-null (#21311, reverts #19332).
     * glob() pattern and fnmatch() pattern soft-null (#21366, ext/standard/file.c, fnmatch.c).
     * openssl_encrypt/openssl_decrypt $data soft-null (#21445, reverts #20263; ext/openssl/openssl.c).
     * openssl_digest($data) soft-null (#21517, reverts #20207; ext/openssl/openssl.c).
     * sodium_bin2hex($string) / sodium_hex2bin($string,$ignore) soft-null (#21517/#24772, reverts #20196; ext/sodium).
     * ftp_connect/ftp_ssl_connect $hostname soft-null (#21757, ext/ftp/ftp.c).
     * implode/join $separator soft-null (#21210, reverts #19894).
     * header($header), preg_quote($str), printf/fprintf($format) soft-null (#21234, reverts #19224/#20197).
     * vprintf/vfprintf($format) soft-null (#21514).
     * token_get_all($code) soft-null (#21503, reverts #19894; ext/tokenizer/tokenizer.c).
     * gettext/_/dgettext/ngettext msgid + domain soft-null (#21581, reverts #20209 TypeError; ext/gettext/gettext.c).
     */
    public static function lowerTrimFamilyString(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        return self::lower($context, $arg, $function, $argIndex, $paramName, 'string', null, false, false);
    }

    public static function lower(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null,
        bool $rejectNullOnForwardProfile = true,
        bool $zparamStrNullGuard = false
    ): Value {
        JitNativeString::ensureInsertBlock($context);
        $arrayExpected = $arrayExpectedType ?? $expectedType;
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            if (
                $context->callerStrictTypes
                || ($zparamStrNullGuard && self::requiresZparamStrStrictNullOnForwardProfile())
                || ($rejectNullOnForwardProfile && self::requiresForwardProfileStrictStringNull())
            ) {
                self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);

                return self::unreachableStringPtr($context);
            }

            if (!self::requiresForwardProfileStrictStringNull()) {
                self::emitNullStringParamDeprecation($context, $function, $argIndex, $paramName, $expectedType);
            }

            return $context->builder->load($context->constantStringFromString(''));
        }
        if (Variable::TYPE_HASHTABLE === $arg->type) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $arrayExpected);

            return self::unreachableStringPtr($context);
        }
        if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $arrayExpected);

            return self::unreachableStringPtr($context);
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            if ('string' !== $expectedType) {
                $magic = MagicMethodDispatch::coerceObjectToString($context, $arg);
                if (null !== $magic) {
                    return $context->helper->loadValue($magic);
                }
            }
            self::emitRejectTypeError($context, $arg, $function, $argIndex, $paramName, $expectedType);

            return self::unreachableStringPtr($context);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            // Boxed null (common when null is arg #1 before an array literal) — Z_PARAM_STR
            // TypeError on 8.4 / strict_types (#19894; same mask as JitStrlen).
            if (
                $context->callerStrictTypes
                || ($zparamStrNullGuard && self::requiresZparamStrStrictNullOnForwardProfile())
                || ($rejectNullOnForwardProfile && self::requiresForwardProfileStrictStringNull())
            ) {
                TypeErrorRaise::registerDeclarations($context);
                TypeErrorRaise::ensureLinked($context);
                $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
                $map = $context->structFieldMap['__value__'];
                $typeByte = $context->builder->load(
                    $context->builder->structGep($valuePtr, $map['type'])
                );
                $i8 = $context->getTypeFromString('int8');
                $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
                $nullErrBlock = BasicBlockHelper::append($context, 'zparam_str_value_null');
                $okBlock = BasicBlockHelper::append($context, 'zparam_str_value_ok');
                $context->builder->branchIf(
                    $context->builder->icmp(
                        Builder::INT_EQ,
                        $typeKind,
                        $i8->constInt(VmVariable::TYPE_NULL, false)
                    ),
                    $nullErrBlock,
                    $okBlock
                );
                $context->builder->positionAtEnd($nullErrBlock);
                self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);
                $context->builder->positionAtEnd($okBlock);
            }
            // Boxed strings: readString from the value box. Do NOT run
            // JitNativeString::coerce + loadValue — that path miscompiles under
            // thin AOT for KIND_VALUE boxes (sodium AEAD #27318, gzcompress/bin2hex).
            return JitStringArg::stringPtrFromVariable($context, $arg);
        }

        return JitStringArg::lower($context, $arg, "{$function}() argument #" . ($argIndex + 1));
    }

    /**
     * Z_PARAM_STR_OR_NULL — null passes through; enum case rejects with ?string TypeError (#17196, ext/standard/info.c).
     */
    public static function lowerNullableString(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        JitNativeString::ensureInsertBlock($context);
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        return self::lower($context, $arg, $function, $argIndex, $paramName, '?string');
    }

    /**
     * Z_PARAM_PATH — soft-null DEP+coerce on 8.4; TypeError only under caller strict_types (#20362, #19146).
     *
     * $softNullPath is retained for call-site clarity; Z_PARAM_PATH is always soft-null outside strict_types.
     */
    public static function lowerPath(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null,
        bool $softNullPath = true
    ): Value {
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            JitNativeString::ensureInsertBlock($context);
            if ($context->callerStrictTypes
                || (!$softNullPath && self::requiresZparamStrStrictNullOnForwardProfile())) {
                self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);

                return self::unreachableStringPtr($context);
            }

            self::emitNullStringParamDeprecation($context, $function, $argIndex, $paramName, $expectedType);

            return $context->builder->load($context->constantStringFromString(''));
        }

        // filestat stubs: typed string $filename on 8.4+ (#5122, ext/standard/filestat.c).
        if (VmString::requiresTypedPathStringOnForwardProfile()) {
            return self::lowerRequiredString($context, $arg, $function, $argIndex, $paramName, $expectedType);
        }

        // Boxed null / VALUE: soft-coerce + DEP (Z_PARAM_PATH; #20362). Strict_types still TypeError via lower().
        return self::lower(
            $context,
            $arg,
            $function,
            $argIndex,
            $paramName,
            $expectedType,
            $arrayExpectedType,
            false,
            false
        );
    }

    public static function emitNullStringParamDeprecation(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string'
    ): void {
        $message = \sprintf(
            '%s(): Passing null to parameter #%d ($%s) of type %s is deprecated',
            $function,
            $argIndex + 1,
            $paramName,
            $expectedType
        );
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_DEPRECATED, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    /**
     * Lower typed string builtin operands (php-src IS_STRING; rejects null, #12640).
     */
    public static function lowerTypedString(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null
    ): Value {
        JitNativeString::ensureInsertBlock($context);
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);

            return self::unreachableStringPtr($context);
        }

        return self::lower($context, $arg, $function, $argIndex, $paramName, $expectedType, $arrayExpectedType);
    }

    /**
     * Lower Z_PARAM_STR operands with __toString coercion when caller is not strict (#11398, ext/standard/string.c).
     */
    public static function lowerCoercible(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null,
        bool $allowStringableUnderStrict = false
    ): Value {
        JitNativeString::ensureInsertBlock($context);
        $arrayExpected = $arrayExpectedType ?? $expectedType;
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            if ($context->callerStrictTypes) {
                self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);

                return self::unreachableStringPtr($context);
            }

            return $context->builder->load($context->constantStringFromString(''));
        }
        if (Variable::TYPE_HASHTABLE === $arg->type) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $arrayExpected);

            return self::unreachableStringPtr($context);
        }
        if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $arrayExpected);

            return self::unreachableStringPtr($context);
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            if ($context->callerStrictTypes && !$allowStringableUnderStrict) {
                self::emitRejectTypeError($context, $arg, $function, $argIndex, $paramName, $expectedType);

                return self::unreachableStringPtr($context);
            }
            $native = JitNativeString::coerce($context, $arg);

            return $context->helper->loadValue($native);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerCoercibleBoxed(
                $context,
                $arg,
                $function,
                $argIndex,
                $paramName,
                $expectedType,
                $arrayExpected,
                $allowStringableUnderStrict
            );
        }

        return JitStringArg::lower($context, $arg, "{$function}() argument #" . ($argIndex + 1));
    }

    private static function lowerCoercibleBoxed(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType,
        string $arrayExpectedType,
        bool $allowStringableUnderStrict = false
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $arrayTy = $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false);
        $objectTy = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $okBlock = BasicBlockHelper::append($context, 'str_coercible_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'str_coercible_array');
        $rejectBlock = BasicBlockHelper::append($context, 'str_coercible_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'str_coercible_coerce');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $arrayExpectedType);

        $context->builder->positionAtEnd($okBlock);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $enumRejectBlock = BasicBlockHelper::append($context, 'str_coercible_enum_reject');
        $objectRejectBlock = BasicBlockHelper::append($context, 'str_coercible_object_reject');
        $context->builder->positionAtEnd($rejectBlock);
        $context->builder->branchIf($isEnumCase, $enumRejectBlock, $objectRejectBlock);
        $context->builder->positionAtEnd($enumRejectBlock);
        self::emitRuntimeBoxedEnumCaseReject(
            $context,
            $valuePtr,
            $function,
            $argIndex,
            $paramName,
            $expectedType
        );
        $context->builder->positionAtEnd($objectRejectBlock);
        if ($context->callerStrictTypes && !$allowStringableUnderStrict) {
            self::emitRuntimeBoxedObjectReject(
                $context,
                $valuePtr,
                $function,
                $argIndex,
                $paramName,
                $expectedType
            );
        } else {
            $objVar = new Variable(
                $context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                $valuePtr
            );
            $native = JitNativeString::coerce($context, $objVar);

            return $context->helper->loadValue($native);
        }

        $context->builder->positionAtEnd($coerceBlock);
        if ($context->callerStrictTypes) {
            $isString = $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            );
            $strictOkBlock = BasicBlockHelper::append($context, 'str_coercible_strict_ok');
            $strictErrBlock = BasicBlockHelper::append($context, 'str_coercible_strict_err');
            $context->builder->branchIf($isString, $strictOkBlock, $strictErrBlock);
            $context->builder->positionAtEnd($strictErrBlock);
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'mixed', $expectedType);
            $context->builder->positionAtEnd($strictOkBlock);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    /**
     * Emit a Z_PARAM_STR TypeError for a runtime object operand (#10166, ext/standard/string.c).
     */
    public static function emitObjectTypeErrorReject(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex = 0,
        string $paramName = 'string',
        string $expectedType = 'string'
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitRuntimeObjectReject($context, $arg, $function, $argIndex, $paramName, $expectedType);

            return;
        }
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);
    }

    /**
     * Lower string builtin operands with strict Z_PARAM_STR parity (#5018, ext/standard/string.c).
     *
     * Rejects int/float/bool operands that {@see lower()} would coerce via JitStringArg.
     */
    public static function lowerRequiredString(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string'
    ): Value {
        if (Variable::TYPE_HASHTABLE === $arg->type || Variable::TYPE_OBJECT === $arg->type) {
            return self::lower($context, $arg, $function, $argIndex, $paramName, $expectedType);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerRequiredBoxed($context, $arg, $function, $argIndex, $paramName, $expectedType);
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            $errBlock = BasicBlockHelper::append($context, 'str_req_scalar_err');
            $context->builder->branch($errBlock);
            $context->builder->positionAtEnd($errBlock);
            self::emitTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg),
                $expectedType
            );

            return self::unreachableStringPtr($context);
        }

        return $context->helper->loadValue($arg);
    }

    private static function lowerRequiredBoxed(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string'
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $arrayTy = $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false);
        $objectTy = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $stringTy = $i8->constInt(VmVariable::TYPE_STRING, false);
        $nullTy = $i8->constInt(VmVariable::TYPE_NULL, false);

        $nullBlock = BasicBlockHelper::append($context, 'str_req_null');
        $afterNull = BasicBlockHelper::append($context, 'str_req_after_null');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeKind, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);

        $context->builder->positionAtEnd($afterNull);
        $arrayBlock = BasicBlockHelper::append($context, 'str_req_array');
        $rejectBlock = BasicBlockHelper::append($context, 'str_req_reject');
        $stringBlock = BasicBlockHelper::append($context, 'str_req_string');
        $scalarBlock = BasicBlockHelper::append($context, 'str_req_scalar');
        $okBlock = BasicBlockHelper::append($context, 'str_req_ok');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $expectedType);

        $context->builder->positionAtEnd($okBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $scalarBlock);

        $enumRejectBlock = BasicBlockHelper::append($context, 'str_req_enum_reject');
        $objectRejectBlock = BasicBlockHelper::append($context, 'str_req_object_reject');
        $context->builder->positionAtEnd($rejectBlock);
        $context->builder->branchIf($isEnumCase, $enumRejectBlock, $objectRejectBlock);
        $context->builder->positionAtEnd($enumRejectBlock);
        self::emitRuntimeBoxedEnumCaseReject(
            $context,
            $valuePtr,
            $function,
            $argIndex,
            $paramName,
            $expectedType
        );
        $context->builder->positionAtEnd($objectRejectBlock);
        self::emitRuntimeBoxedObjectReject(
            $context,
            $valuePtr,
            $function,
            $argIndex,
            $paramName,
            $expectedType
        );

        $context->builder->positionAtEnd($scalarBlock);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeKind, $stringTy);
        $scalarErrBlock = BasicBlockHelper::append($context, 'str_req_scalar_err');
        $context->builder->branchIf($isString, $stringBlock, $scalarErrBlock);

        $context->builder->positionAtEnd($scalarErrBlock);
        self::emitRuntimeBoxedNonStringScalarReject(
            $context,
            $valuePtr,
            $typeKind,
            $function,
            $argIndex,
            $paramName,
            $expectedType
        );

        $context->builder->positionAtEnd($stringBlock);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    private static function lowerBoxed(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        string $arrayExpectedType = 'string'
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $arrayTy = $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false);
        $objectTy = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $okBlock = BasicBlockHelper::append($context, 'str_builtin_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'str_builtin_array');
        $rejectBlock = BasicBlockHelper::append($context, 'str_builtin_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'str_builtin_coerce');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $arrayExpectedType);

        $context->builder->positionAtEnd($okBlock);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $enumRejectBlock = BasicBlockHelper::append($context, 'str_builtin_enum_reject');
        $objectRejectBlock = BasicBlockHelper::append($context, 'str_builtin_object_reject');
        $context->builder->positionAtEnd($rejectBlock);
        $context->builder->branchIf($isEnumCase, $enumRejectBlock, $objectRejectBlock);
        $context->builder->positionAtEnd($enumRejectBlock);
        self::emitRuntimeBoxedEnumCaseReject(
            $context,
            $valuePtr,
            $function,
            $argIndex,
            $paramName,
            $expectedType
        );
        $context->builder->positionAtEnd($objectRejectBlock);
        self::emitRuntimeBoxedObjectReject(
            $context,
            $valuePtr,
            $function,
            $argIndex,
            $paramName,
            $expectedType
        );

        $context->builder->positionAtEnd($coerceBlock);
        if ($context->callerStrictTypes) {
            $isString = $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            );
            $strictOkBlock = BasicBlockHelper::append($context, 'str_builtin_strict_ok');
            $strictErrBlock = BasicBlockHelper::append($context, 'str_builtin_strict_err');
            $context->builder->branchIf($isString, $strictOkBlock, $strictErrBlock);
            $context->builder->positionAtEnd($strictErrBlock);
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'mixed', $expectedType);
            $context->builder->positionAtEnd($strictOkBlock);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    private static function emitRejectTypeError(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string'
    ): void {
        $compileTimeLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $compileTimeLabel) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, $compileTimeLabel, $expectedType);

            return;
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitRuntimeObjectReject($context, $arg, $function, $argIndex, $paramName, $expectedType);

            return;
        }
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);
    }

    private static function emitRuntimeObjectReject(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType
    ): void {
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);

            return;
        }
        $objPtr = Variable::KIND_VALUE === $arg->kind
            ? $arg->value
            : $context->builder->load($arg->value);
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        self::emitRuntimeEnumClassIdReject($context, $classId, $function, $argIndex, $paramName, $expectedType);
    }

    private static function emitRuntimeBoxedEnumCaseReject(
        Context $context,
        Value $valuePtr,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType
    ): void {
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null === $enumMap || !isset($enumMap['class_id'])) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);

            return;
        }
        $classId = $context->builder->load(
            $context->builder->structGep($valuePtr, $enumMap['class_id'])
        );
        self::emitRuntimeEnumClassIdReject($context, $classId, $function, $argIndex, $paramName, $expectedType);
    }

    /**
     * Boxed enum-case vs object reject for strlen() Z_PARAM_STR parity (#10166).
     */
    public static function emitRuntimeBoxedRejectForStrlen(
        Context $context,
        Value $valuePtr,
        Value $isEnumCase
    ): void {
        $enumRejectBlock = BasicBlockHelper::append($context, 'strlen_enum_reject');
        $objectRejectBlock = BasicBlockHelper::append($context, 'strlen_object_reject');
        $context->builder->branchIf($isEnumCase, $enumRejectBlock, $objectRejectBlock);
        $context->builder->positionAtEnd($enumRejectBlock);
        self::emitRuntimeBoxedEnumCaseReject($context, $valuePtr, 'strlen', 0, 'string', 'string');
        $context->builder->positionAtEnd($objectRejectBlock);
        self::emitRuntimeBoxedObjectReject($context, $valuePtr, 'strlen', 0, 'string', 'string');
    }

    private static function emitRuntimeBoxedNonStringScalarReject(
        Context $context,
        Value $valuePtr,
        Value $typeKind,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string'
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $longTy = $i8->constInt(VmVariable::TYPE_INTEGER & 0x7f, false);
        $doubleTy = $i8->constInt(VmVariable::TYPE_FLOAT & 0x7f, false);
        $boolTy = $i8->constInt(VmVariable::TYPE_BOOLEAN & 0x7f, false);

        $intErrBlock = BasicBlockHelper::append($context, 'str_req_scalar_int');
        $afterInt = BasicBlockHelper::append($context, 'str_req_after_int');
        $floatErrBlock = BasicBlockHelper::append($context, 'str_req_scalar_float');
        $afterFloat = BasicBlockHelper::append($context, 'str_req_after_float');
        $boolErrBlock = BasicBlockHelper::append($context, 'str_req_scalar_bool');
        $mixedErrBlock = BasicBlockHelper::append($context, 'str_req_scalar_mixed');

        $isInt = $context->builder->icmp(Builder::INT_EQ, $typeKind, $longTy);
        $context->builder->branchIf($isInt, $intErrBlock, $afterInt);

        $context->builder->positionAtEnd($intErrBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'int', $expectedType);

        $context->builder->positionAtEnd($afterInt);
        $isFloat = $context->builder->icmp(Builder::INT_EQ, $typeKind, $doubleTy);
        $context->builder->branchIf($isFloat, $floatErrBlock, $afterFloat);

        $context->builder->positionAtEnd($floatErrBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'float', $expectedType);

        $context->builder->positionAtEnd($afterFloat);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeKind, $boolTy);
        $context->builder->branchIf($isBool, $boolErrBlock, $mixedErrBlock);

        // zend_execute.c — bool actuals print true/false (#29097).
        // Use JitValueBox::readBoolByte — there is no __value__readBool (#29109; #21892).
        $context->builder->positionAtEnd($boolErrBlock);
        $boolByte = JitValueBox::readBoolByte($context, $valuePtr);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $context->getTypeFromString('int8')->constInt(0, false)
        );
        $trueErr = BasicBlockHelper::append($context, 'str_req_scalar_true');
        $falseErr = BasicBlockHelper::append($context, 'str_req_scalar_false');
        $context->builder->branchIf($isTrue, $trueErr, $falseErr);
        $context->builder->positionAtEnd($trueErr);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'true', $expectedType);
        $context->builder->positionAtEnd($falseErr);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'false', $expectedType);

        $context->builder->positionAtEnd($mixedErrBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'mixed', $expectedType);
    }

    private static function emitRuntimeBoxedObjectReject(
        Context $context,
        Value $valuePtr,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType
    ): void {
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);

            return;
        }
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        self::emitRuntimeEnumClassIdReject($context, $classId, $function, $argIndex, $paramName, $expectedType);
    }

    private static function emitRuntimeEnumClassIdReject(
        Context $context,
        Value $classId,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType
    ): void {
        $jitObject = $context->type->object;
        if (!$jitObject instanceof JitObjectType) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);

            return;
        }
        $ids = [];
        foreach ($jitObject->allDeclaredClassLowerNames() as $lc) {
            $declaredId = $jitObject->lookup($lc);
            $ids[] = [$declaredId, $jitObject->classNameForId($declaredId)];
        }
        if ([] === $ids) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $fn = BasicBlockHelper::parentFunction($context);
        $checkBlock = $context->builder->getInsertBlock();
        $okBlock = BasicBlockHelper::append($context, 'str_class_id_reject_ok');
        $lastIdx = \count($ids) - 1;
        foreach ($ids as $idx => [$declaredId, $className]) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($declaredId, false)
            );
            $rejectBlock = $fn->appendBasicBlock('str_class_id_reject_'.$declaredId);
            $nextBlock = $idx === $lastIdx
                ? $okBlock
                : $fn->appendBasicBlock('str_class_id_try_'.($idx + 1));
            $context->builder->branchIf($match, $rejectBlock, $nextBlock);
            $context->builder->positionAtEnd($rejectBlock);
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, $className, $expectedType);
            $checkBlock = $nextBlock;
        }
        if ($checkBlock !== $okBlock) {
            $context->builder->positionAtEnd($checkBlock);
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);
            $context->builder->branch($okBlock);
        }
        $context->builder->positionAtEnd($okBlock);
    }

    private static function typeErrorMessage(
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

    private static function emitTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given,
        string $expectedType = 'string'
    ): void {
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            self::typeErrorMessage($function, $argIndex, $paramName, $given, $expectedType)
        );
    }

    private static function unreachableStringPtr(Context $context): Value
    {
        return $context->getTypeFromString('__string__*')->constNull();
    }

    /** Compile-time string operand for builtins that only lower literals (timezone_open, date_create, …). */
    public static function compileTimeLiteral(Variable $arg): ?string
    {
        return JitStringArg::compileTimeLiteral($arg);
    }

    /**
     * Reject empty string operands after lowering (php-src dir.c / ini.c empty-path guards; #11031).
     *
     * @throws \ValueError when the compile-time operand is empty (JIT hybrid try/catch).
     * AOT: prefer a non-literal empty path so lowering emits the runtime length check (#29268).
     */
    public static function rejectEmpty(Context $context, Variable $arg, Value $loweredStr, string $errorMessage): void
    {
        if (null !== ($arg->compileTimeString ?? null)) {
            if ('' === $arg->compileTimeString) {
                throw new \ValueError($errorMessage);
            }

            return;
        }

        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($loweredStr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $context->builder->not($empty),
            'str_empty',
            $errorMessage
        );
    }
}
