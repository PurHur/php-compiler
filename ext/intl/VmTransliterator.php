<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * Transliterator create/transliterate/createFromRules/createInverse/listIDs — ICU utrans_* via FFI
 * + Latin-ASCII PHP fallback (#6139, #20719, #20915).
 *
 * php-src: ext/intl/transliterator/transliterator_methods.c / transliterator_class.c
 * ICU: unicode/utrans.h — utrans_openU / utrans_openInverse / utrans_openIDs / utrans_transUChars /
 * utrans_getUnicodeID (public readonly $id)
 */
final class VmTransliterator
{
    public const CLASS_LC = 'transliterator';

    /** php-src Transliterator::$id (transliterator.stub.php; #20915). */
    public const PROP_ID = 'id';

    public const FORWARD = 0;
    public const REVERSE = 1;

    /** Fixed id used by php-src transliterator_create_from_rules (RulesTransPHP). */
    private const RULES_ID = 'RulesTransPHP';

    /** @var array<int, array{id: string, handle: object|null, use_fallback: bool, errorCode: int, errorMessage: string}> */
    private static array $state = [];

    private static ?\FFI $ffi = null;

    private static string $symSuffix = '';

    private static bool $ffiUnavailable = false;

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'FORWARD' => self::FORWARD,
            'REVERSE' => self::REVERSE,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('Transliterator');
        $entry->isInternal = true;
        // php-src Transliterator historically allows dynamic props (zend_std); also unlocks
        // get_object_vars() for declared public $id (#20915, collectObjectVarsForBuiltin).
        $entry->allowsDynamicProperties = true;
        foreach (self::classConstants() as $name => $value) {
            $lc = strtolower($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        // public readonly string $id — php-src transliterator.stub.php / utrans_getUnicodeID (#20915).
        $strProto = new Variable(Variable::TYPE_STRING);
        $strProto->string('');
        $entry->properties[] = new ClassProperty(
            self::PROP_ID,
            null,
            $strProto,
            true,
            $pub,
            self::CLASS_LC
        );
        $methods = [
            'create' => [new TransliteratorCreate(), $pubStatic],
            'createfromrules' => [new TransliteratorCreateFromRules(), $pubStatic],
            'createinverse' => [new TransliteratorCreateInverse(), $pub],
            'listids' => [new TransliteratorListIDs(), $pubStatic],
            'transliterate' => [new TransliteratorTransliterate(), $pub],
            'geterrorcode' => [new TransliteratorGetErrorCode(), $pub],
            'geterrormessage' => [new TransliteratorGetErrorMessage(), $pub],
        ];
        $names = [
            'create' => 'create',
            'createfromrules' => 'createFromRules',
            'createinverse' => 'createInverse',
            'listids' => 'listIDs',
            'transliterate' => 'transliterate',
            'geterrorcode' => 'getErrorCode',
            'geterrormessage' => 'getErrorMessage',
        ];
        foreach ($methods as $lc => [$handler, $vis]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $names[$lc];
        }
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function isTransliteratorObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    /**
     * @return ObjectEntry|null null + intl error for unknown IDs (php-src returns null)
     */
    public static function create(Context $ctx, string $id, int $direction = self::FORWARD): ?ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "Transliterator" not found');
        }
        if ('' === $id) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'transliterator_create: id is empty: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return null;
        }
        $handle = self::openTransliterator($id, $direction);
        $fallback = null === $handle && self::supportsFallbackId($id);
        if (null === $handle && !$fallback) {
            // php-src transliterator_methods.c — utrans_openU failure → U_INVALID_ID (#25355).
            IntlError::set(
                IntlError::U_INVALID_ID,
                'transliterator_create: unable to open ICU transliterator with id "'.$id.'": U_INVALID_ID'
            );

            return null;
        }
        return self::finishConstruct($ctx, $id, $handle, $fallback, 'transliterator_create');
    }

    /**
     * transliterator_create_from_rules — php-src opens utrans_openU("RulesTransPHP", rules=…).
     *
     * @return ObjectEntry|null
     */
    public static function createFromRules(Context $ctx, string $rules, int $direction = self::FORWARD): ?ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "Transliterator" not found');
        }
        self::assertDirection($direction, 'transliterator_create_from_rules');
        if ('' === $rules) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'transliterator_create_from_rules: rules is empty: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return null;
        }
        $handle = self::openTransliterator(self::RULES_ID, $direction, $rules);
        if (null === $handle) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'transliterator_create_from_rules: unable to create ICU transliterator from rules: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return null;
        }

        return self::finishConstruct($ctx, self::RULES_ID, $handle, false, 'transliterator_create_from_rules');
    }

    /**
     * transliterator_create_inverse — utrans_openInverse (#20719).
     *
     * @return ObjectEntry|null
     */
    public static function createInverse(Context $ctx, ObjectEntry $orig): ?ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "Transliterator" not found');
        }
        $state = self::$state[$orig->id] ?? null;
        if (null === $state || null === $state['handle']) {
            $msg = 'transliterator_create_inverse: could not create inverse ICU transliterator: U_ILLEGAL_ARGUMENT_ERROR';
            IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, $msg);
            self::setObjectError($orig, IntlError::U_ILLEGAL_ARGUMENT_ERROR, $msg);

            return null;
        }
        $inv = self::openInverse($state['handle']);
        if (null === $inv) {
            $msg = 'transliterator_create_inverse: could not create inverse ICU transliterator: U_ILLEGAL_ARGUMENT_ERROR';
            IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, $msg);
            self::setObjectError($orig, IntlError::U_ILLEGAL_ARGUMENT_ERROR, $msg);

            return null;
        }

        return self::finishConstruct($ctx, $state['id'].'/inverse', $inv, false, 'transliterator_create_inverse');
    }

    /**
     * transliterator_list_ids — utrans_openIDs + uenum_unext (#20719).
     *
     * @return HashTable|false
     */
    public static function listIDs()
    {
        $ids = self::enumerateIds();
        if (null === $ids) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'transliterator_list_ids: Failed to obtain registered transliterators: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();
        $ht = new HashTable();
        foreach ($ids as $id) {
            $slot = new Variable();
            $slot->string($id);
            $ht->append($slot);
        }

        return $ht;
    }

    public static function getErrorCode(ObjectEntry $tr): int
    {
        $state = self::$state[$tr->id] ?? null;

        return null === $state ? IntlError::U_ZERO_ERROR : $state['errorCode'];
    }

    public static function getErrorMessage(ObjectEntry $tr): string
    {
        $state = self::$state[$tr->id] ?? null;

        return null === $state ? 'U_ZERO_ERROR' : $state['errorMessage'];
    }

    /**
     * @return string|false
     */
    public static function transliterate(ObjectEntry $tr, string $subject, int $start = 0, int $end = -1)
    {
        $state = self::$state[$tr->id] ?? null;
        if (null === $state) {
            $msg = 'transliterator_transliterate: bad transliterator: U_ILLEGAL_ARGUMENT_ERROR';
            IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, $msg);

            return false;
        }
        $len = \strlen($subject);
        if ($start < 0) {
            $start = 0;
        }
        if ($end < 0 || $end > $len) {
            $end = $len;
        }
        if ($start > $end) {
            IntlError::clear();
            self::setObjectError($tr, IntlError::U_ZERO_ERROR, 'U_ZERO_ERROR');

            return $subject;
        }
        $prefix = substr($subject, 0, $start);
        $middle = substr($subject, $start, $end - $start);
        $suffix = substr($subject, $end);
        IntlError::clear();
        if (null !== $state['handle']) {
            $converted = self::transUChars($state['handle'], $middle);
            if (null === $converted) {
                $msg = 'transliterator_transliterate: transliteration failed: U_ILLEGAL_ARGUMENT_ERROR';
                IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, $msg);
                self::setObjectError($tr, IntlError::U_ILLEGAL_ARGUMENT_ERROR, $msg);

                return false;
            }
            self::setObjectError($tr, IntlError::U_ZERO_ERROR, 'U_ZERO_ERROR');

            return $prefix.$converted.$suffix;
        }
        if ($state['use_fallback']) {
            self::setObjectError($tr, IntlError::U_ZERO_ERROR, 'U_ZERO_ERROR');

            return $prefix.self::fallbackLatinAscii($middle).$suffix;
        }

        return false;
    }

    /** @return ObjectEntry */
    private static function finishConstruct(
        Context $ctx,
        string $id,
        ?object $handle,
        bool $fallback,
        string $op
    ): ObjectEntry {
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        // Match php-src transliterator_object_construct: property from utrans_getUnicodeID (#20915).
        $displayId = self::unicodeIdFromHandle($handle) ?? $id;
        $object->getProperty(self::PROP_ID)->string($displayId);
        $object->constructed = true;
        self::$state[$object->id] = [
            'id' => $displayId,
            'handle' => $handle,
            'use_fallback' => $fallback,
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];
        if ($fallback) {
            IntlError::set(
                IntlError::U_USING_FALLBACK_WARNING,
                $op.': ICU unavailable; using Latin-ASCII PHP fallback: U_USING_DEFAULT_WARNING'
            );
        } elseif (IntlError::U_ZERO_ERROR === IntlError::getCode()) {
            IntlError::clear();
        }

        return $object;
    }

    /**
     * php-src transliterator_object_construct — utrans_getUnicodeID → UTF-8 $id property.
     *
     * @param object|null $handle UTransliterator*
     */
    private static function unicodeIdFromHandle(?object $handle): ?string
    {
        if (null === $handle) {
            return null;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'utrans_getUnicodeID'.self::$symSuffix;
        try {
            $len = $ffi->new('int32_t');
            $len->cdata = 0;
            $uchars = $ffi->$fn($handle, \FFI::addr($len));
            if (null === $uchars) {
                return null;
            }

            return self::uCharsToUtf8($uchars, (int) $len->cdata);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function setObjectError(ObjectEntry $tr, int $code, string $message): void
    {
        if (!isset(self::$state[$tr->id])) {
            return;
        }
        self::$state[$tr->id]['errorCode'] = $code;
        self::$state[$tr->id]['errorMessage'] = $message;
    }

    public static function assertDirection(int $direction, string $function): void
    {
        if (self::FORWARD !== $direction && self::REVERSE !== $direction) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #2 ($direction) must be either Transliterator::FORWARD or Transliterator::REVERSE',
                $function
            ));
        }
    }

    public static function coerceIdArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'id');
    }

    public static function coerceSubjectArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'string');
    }

    /**
     * Procedural transliterator_transliterate() arg #1 — Z_PARAM_OBJ_OF_CLASS_OR_STR
     * (php-src transliterator_methods.c, #22161).
     *
     * @return ObjectEntry|null Transliterator object, or null when string ID create failed
     *                       (intl error already set; caller emits E_WARNING + returns false)
     */
    public static function resolveProceduralTransliteratorArg(Frame $frame, Variable $var): ?ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            $object = $var->toObject();
            if (self::isTransliteratorObject($object)) {
                return $object;
            }
            throw new \TypeError(\sprintf(
                'transliterator_transliterate(): Argument #1 ($transliterator) must be of type Transliterator|string, %s given',
                $object->class->name
            ));
        }
        if (InternalStrictArg::isCallerStrict($frame)) {
            if (Variable::TYPE_STRING !== $var->type) {
                throw new \TypeError(\sprintf(
                    'transliterator_transliterate(): Argument #1 ($transliterator) must be of type Transliterator|string, %s given',
                    ReflectionSupport::valueTypeLabelPublic($var)
                ));
            }
            $id = $var->toString();
        } else {
            $id = VmString::coerceStringBuiltinArg(
                $var,
                'transliterator_transliterate',
                0,
                'transliterator',
                'Transliterator|string'
            );
        }
        $object = self::create($frame->vmContext, $id, self::FORWARD);
        if (null === $object) {
            $frame->vmContext->errors->languageWarning(
                \sprintf(
                    'transliterator_transliterate(): Could not create transliterator with ID "%s" (%s)',
                    $id,
                    IntlError::getMessage()
                ),
                null,
                0,
                $frame->vmContext,
                $frame
            );
        }

        return $object;
    }

    /** Drop temp Transliterator state after procedural string-ID path (#22161). */
    public static function release(ObjectEntry $tr): void
    {
        unset(self::$state[$tr->id]);
    }

    /**
     * Compile-time / host fold for string-ID transliterate (AOT literal path, #22161).
     *
     * @return string|false
     */
    public static function transliterateId(string $id, string $subject, int $start = 0, int $end = -1)
    {
        if ('' === $id) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'transliterator_create: id is empty: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $handle = self::openTransliterator($id, self::FORWARD);
        $fallback = null === $handle && self::supportsFallbackId($id);
        if (null === $handle && !$fallback) {
            // php-src transliterator_methods.c — utrans_openU failure → U_INVALID_ID (#25355).
            IntlError::set(
                IntlError::U_INVALID_ID,
                'transliterator_create: unable to open ICU transliterator with id "'.$id.'": U_INVALID_ID'
            );

            return false;
        }
        $len = \strlen($subject);
        if ($start < 0) {
            $start = 0;
        }
        if ($end < 0 || $end > $len) {
            $end = $len;
        }
        if ($start > $end) {
            IntlError::clear();

            return $subject;
        }
        $prefix = substr($subject, 0, $start);
        $middle = substr($subject, $start, $end - $start);
        $suffix = substr($subject, $end);
        IntlError::clear();
        if (null !== $handle) {
            $converted = self::transUChars($handle, $middle);
            $close = 'utrans_close'.self::$symSuffix;
            $ffi = self::ffi();
            if (null !== $ffi) {
                try {
                    $ffi->$close($handle);
                } catch (\Throwable) {
                }
            }
            if (null === $converted) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'transliterator_transliterate: transliteration failed: U_ILLEGAL_ARGUMENT_ERROR'
                );

                return false;
            }

            return $prefix.$converted.$suffix;
        }

        return $prefix.self::fallbackLatinAscii($middle).$suffix;
    }

    public static function coerceDirectionArg(Variable $var, string $function, int $position): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_NULL === $var->type) {
            return self::FORWARD;
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1 : 0;
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($direction) must be of type int, %s given',
            $function,
            $position + 1,
            \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($var)
        ));
    }

    private static function supportsFallbackId(string $id): bool
    {
        $norm = strtolower(str_replace(' ', '', $id));

        return 'any-latin;latin-ascii' === $norm
            || 'latin-ascii' === $norm
            || 'any-latin' === $norm
            || 'nfd;[:nonspacing mark:]remove;nfc' === $norm;
    }

    private static function fallbackLatinAscii(string $subject): string
    {
        if (\function_exists('iconv')) {
            $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $subject);
            if (false !== $out) {
                return $out;
            }
        }
        // Strip combining marks after NFD-ish decompose of common Latin.
        $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'ç' => 'c',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ý' => 'Y', 'Ñ' => 'N', 'Ç' => 'C',
        ];

        return strtr($subject, $map);
    }

    /** @return object|null FFI CData UTransliterator* */
    private static function openTransliterator(string $id, int $direction, ?string $rules = null): ?object
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $open = 'utrans_openU'.self::$symSuffix;
        $close = 'utrans_close'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $uId = self::utf8ToUChars($ffi, $id);
            if (null === $uId) {
                return null;
            }
            $uRules = null;
            $rulesLen = -1;
            if (null !== $rules) {
                $uRules = self::utf8ToUChars($ffi, $rules);
                if (null === $uRules) {
                    return null;
                }
                $rulesLen = self::uCharLen($uRules);
            }
            $dir = 0 === $direction ? 0 : 1; // UTRANS_FORWARD / UTRANS_REVERSE
            $trans = $ffi->$open(
                $uId,
                -1,
                $dir,
                $uRules,
                $rulesLen,
                null,
                \FFI::addr($status)
            );
            $code = (int) $status->cdata;
            if (null === $trans || $code > 0) {
                if (null !== $trans) {
                    $ffi->$close($trans);
                }

                return null;
            }

            return $trans;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $handle @return object|null */
    private static function openInverse(object $handle): ?object
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'utrans_openInverse'.self::$symSuffix;
        $close = 'utrans_close'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $trans = $ffi->$fn($handle, \FFI::addr($status));
            if (null === $trans || (int) $status->cdata > 0) {
                if (null !== $trans) {
                    $ffi->$close($trans);
                }

                return null;
            }

            return $trans;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string>|null null when ICU unavailable / enumeration failed */
    private static function enumerateIds(): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $open = 'utrans_openIDs'.self::$symSuffix;
        $next = 'uenum_unext'.self::$symSuffix;
        $close = 'uenum_close'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $en = $ffi->$open(\FFI::addr($status));
            if (null === $en || (int) $status->cdata > 0) {
                return null;
            }
            $ids = [];
            while (true) {
                $status->cdata = 0;
                $len = $ffi->new('int32_t');
                $len->cdata = 0;
                $elem = $ffi->$next($en, \FFI::addr($len), \FFI::addr($status));
                if (null === $elem || (int) $status->cdata > 0) {
                    break;
                }
                $ids[] = self::uCharsToUtf8($elem, (int) $len->cdata);
            }
            $ffi->$close($en);

            return $ids;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $handle */
    private static function transUChars(object $handle, string $utf8): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'utrans_transUChars'.self::$symSuffix;
        try {
            $buf = self::utf8ToUChars($ffi, $utf8);
            if (null === $buf) {
                return null;
            }
            $uLen = self::uCharLen($buf);
            $capacity = max($uLen * 4 + 16, 64);
            $text = $ffi->new('UChar['.$capacity.']');
            for ($i = 0; $i < $uLen; ++$i) {
                $text[$i] = $buf[$i];
            }
            $textLength = $ffi->new('int32_t');
            $textLength->cdata = $uLen;
            $limit = $ffi->new('int32_t');
            $limit->cdata = $uLen;
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $ffi->$fn(
                $handle,
                $text,
                \FFI::addr($textLength),
                $capacity,
                0,
                \FFI::addr($limit),
                \FFI::addr($status)
            );
            if ((int) $status->cdata > 0) {
                return null;
            }

            return self::uCharsToUtf8($text, (int) $textLength->cdata);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return \FFI\CData|null UChar[] */
    private static function utf8ToUChars(\FFI $ffi, string $utf8): ?object
    {
        $codes = [];
        $len = \strlen($utf8);
        $i = 0;
        while ($i < $len) {
            $c = \ord($utf8[$i]);
            if ($c < 0x80) {
                $cp = $c;
                ++$i;
            } elseif (($c & 0xE0) === 0xC0 && $i + 1 < $len) {
                $cp = (($c & 0x1F) << 6) | (\ord($utf8[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($c & 0xF0) === 0xE0 && $i + 2 < $len) {
                $cp = (($c & 0x0F) << 12) | ((\ord($utf8[$i + 1]) & 0x3F) << 6) | (\ord($utf8[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (($c & 0xF8) === 0xF0 && $i + 3 < $len) {
                $cp = (($c & 0x07) << 18) | ((\ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((\ord($utf8[$i + 2]) & 0x3F) << 6) | (\ord($utf8[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $cp = 0xFFFD;
                ++$i;
            }
            if ($cp > 0xFFFF) {
                $cp -= 0x10000;
                $codes[] = 0xD800 | ($cp >> 10);
                $codes[] = 0xDC00 | ($cp & 0x3FF);
            } else {
                $codes[] = $cp;
            }
        }
        $n = \count($codes);
        $arr = $ffi->new('UChar['.($n + 1).']');
        for ($j = 0; $j < $n; ++$j) {
            $arr[$j] = $codes[$j];
        }
        $arr[$n] = 0;

        return $arr;
    }

    /** @param \FFI\CData $buf */
    private static function uCharLen(object $buf): int
    {
        $n = 0;
        while (0 !== (int) $buf[$n]) {
            ++$n;
            if ($n > 1_000_000) {
                break;
            }
        }

        return $n;
    }

    /** @param \FFI\CData $buf */
    private static function uCharsToUtf8(object $buf, int $len): string
    {
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $u = (int) $buf[$i];
            if ($u >= 0xD800 && $u <= 0xDBFF && $i + 1 < $len) {
                $low = (int) $buf[$i + 1];
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $cp = 0x10000 + ((($u - 0xD800) << 10) | ($low - 0xDC00));
                    ++$i;
                    $out .= self::chrUtf8($cp);
                    continue;
                }
            }
            $out .= self::chrUtf8($u);
        }

        return $out;
    }

    private static function chrUtf8(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12)).\chr(0x80 | (($cp >> 6) & 0x3F)).\chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            .\chr(0x80 | (($cp >> 12) & 0x3F))
            .\chr(0x80 | (($cp >> 6) & 0x3F))
            .\chr(0x80 | ($cp & 0x3F));
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            self::$ffiUnavailable = true;

            return null;
        }
        $candidates = [
            ['libicui18n.so.74', '_74'],
            ['libicui18n.so.70', '_70'],
            ['libicui18n.so.72', '_72'],
            ['libicui18n.so.71', '_71'],
            ['libicui18n.so', '_74'],
            ['libicui18n.dylib', ''],
        ];
        foreach ($candidates as [$lib, $suffix]) {
            try {
                self::$ffi = \FFI::cdef(self::cdefForSuffix($suffix), $lib);
                self::$symSuffix = $suffix;

                return self::$ffi;
            } catch (\Throwable) {
                self::$ffi = null;
            }
        }
        self::$ffiUnavailable = true;

        return null;
    }

    private static function cdefForSuffix(string $suffix): string
    {
        return <<<C
typedef int32_t UErrorCode;
typedef uint16_t UChar;
typedef struct UTransliterator UTransliterator;
typedef struct UEnumeration UEnumeration;
UTransliterator *utrans_openU{$suffix}(const UChar *id, int32_t idLength, int32_t dir, const UChar *rules, int32_t rulesLength, void *parseError, UErrorCode *status);
UTransliterator *utrans_openInverse{$suffix}(const UTransliterator *trans, UErrorCode *status);
UEnumeration *utrans_openIDs{$suffix}(UErrorCode *status);
const UChar *uenum_unext{$suffix}(UEnumeration *en, int32_t *resultLength, UErrorCode *status);
void uenum_close{$suffix}(UEnumeration *en);
void utrans_close{$suffix}(UTransliterator *trans);
void utrans_transUChars{$suffix}(const UTransliterator *trans, UChar *text, int32_t *textLength, int32_t textCapacity, int32_t start, int32_t *limit, UErrorCode *status);
const UChar *utrans_getUnicodeID{$suffix}(const UTransliterator *trans, int32_t *resultLength);
C;
    }
}

/** Transliterator::create() — php-src transliterator_create (#6139, AOT #28657). */
final class TransliteratorCreate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'Transliterator::create() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $id = VmTransliterator::coerceIdArg($frame->calledArgs[0], 'Transliterator::create', 0);
        $dir = VmTransliterator::FORWARD;
        if ($argc >= 2) {
            $dir = VmTransliterator::coerceDirectionArg($frame->calledArgs[1], 'Transliterator::create', 1);
        }
        VmTransliterator::assertDirection($dir, 'Transliterator::create');
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTransliterator::create($frame->vmContext, $id, $dir);
        if (null === $object) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($object);
    }

    public function call(\PHPCompiler\JIT\Context $context, \PHPCompiler\JIT\Variable ...$args): \PHPLLVM\Value
    {
        return JitTransliteratorCreate::invoke($context, ...$args);
    }
}

/** Transliterator::createFromRules() — php-src transliterator_create_from_rules (#20719). */
final class TransliteratorCreateFromRules extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromRules');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'Transliterator::createFromRules() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $rules = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Transliterator::createFromRules',
            0,
            'rules'
        );
        $dir = VmTransliterator::FORWARD;
        if ($argc >= 2) {
            $dir = VmTransliterator::coerceDirectionArg($frame->calledArgs[1], 'Transliterator::createFromRules', 1);
        }
        VmTransliterator::assertDirection($dir, 'Transliterator::createFromRules');
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTransliterator::createFromRules($frame->vmContext, $rules, $dir);
        if (null === $object) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($object);
    }
}

/** Transliterator::createInverse() — php-src transliterator_create_inverse (#20719). */
final class TransliteratorCreateInverse extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createInverse');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Transliterator::createInverse() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmTransliterator::isTransliteratorObject($receiver->toObject())) {
            throw new \Error('Transliterator::createInverse() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTransliterator::createInverse($frame->vmContext, $receiver->toObject());
        if (null === $object) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($object);
    }
}

/** Transliterator::listIDs() — php-src transliterator_list_ids (#20719). */
final class TransliteratorListIDs extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('listIDs');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Transliterator::listIDs() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ids = VmTransliterator::listIDs();
        if (false === $ids) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($ids);
    }
}

/** Transliterator::getErrorCode() — php-src transliterator_get_error_code (#20719). */
final class TransliteratorGetErrorCode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getErrorCode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Transliterator::getErrorCode() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmTransliterator::isTransliteratorObject($receiver->toObject())) {
            throw new \Error('Transliterator::getErrorCode() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmTransliterator::getErrorCode($receiver->toObject()));
    }
}

/** Transliterator::getErrorMessage() — php-src transliterator_get_error_message (#20719). */
final class TransliteratorGetErrorMessage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getErrorMessage');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Transliterator::getErrorMessage() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmTransliterator::isTransliteratorObject($receiver->toObject())) {
            throw new \Error('Transliterator::getErrorMessage() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmTransliterator::getErrorMessage($receiver->toObject()));
    }
}

/** Transliterator::transliterate() — php-src transliterator_transliterate (#6139, AOT #28657). */
final class TransliteratorTransliterate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('transliterate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'Transliterator::transliterate() expects between 1 and 3 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmTransliterator::isTransliteratorObject($receiver->toObject())) {
            throw new \Error('Transliterator::transliterate() called on incompatible object');
        }
        $subject = VmTransliterator::coerceSubjectArg($frame->calledArgs[1], 'Transliterator::transliterate', 1);
        $start = 0;
        $end = -1;
        if ($argc >= 3) {
            $start = (int) $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        if ($argc >= 4) {
            $end = (int) $frame->calledArgs[3]->resolveIndirect()->toInt();
        }
        $result = VmTransliterator::transliterate($receiver->toObject(), $subject, $start, $end);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(\PHPCompiler\JIT\Context $context, \PHPCompiler\JIT\Variable ...$args): \PHPLLVM\Value
    {
        return JitTransliteratorTransliterate::invokeMethod($context, ...$args);
    }
}
