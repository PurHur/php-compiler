<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Block;
use PHPCompiler\ext\standard\abs;
use PHPCompiler\ext\standard\addcslashes;
use PHPCompiler\ext\standard\addslashes;
use PHPCompiler\ext\standard\array_change_key_case;
use PHPCompiler\ext\standard\array_column;
use PHPCompiler\ext\standard\array_combine;
use PHPCompiler\ext\standard\array_diff;
use PHPCompiler\ext\standard\array_diff_assoc;
use PHPCompiler\ext\standard\array_diff_key;
use PHPCompiler\ext\standard\array_fill;
use PHPCompiler\ext\standard\array_fill_keys;
use PHPCompiler\ext\standard\array_first;
use PHPCompiler\ext\standard\array_intersect;
use PHPCompiler\ext\standard\array_intersect_assoc;
use PHPCompiler\ext\standard\array_intersect_key;
use PHPCompiler\ext\standard\array_is_list;
use PHPCompiler\ext\standard\array_key_exists;
use PHPCompiler\ext\standard\array_key_first;
use PHPCompiler\ext\standard\array_key_last;
use PHPCompiler\ext\standard\array_keys;
use PHPCompiler\ext\standard\array_last;
use PHPCompiler\ext\standard\array_merge;
use PHPCompiler\ext\standard\array_merge_recursive;
use PHPCompiler\ext\standard\array_pad;
use PHPCompiler\ext\standard\array_replace;
use PHPCompiler\ext\standard\array_replace_recursive;
use PHPCompiler\ext\standard\array_reverse;
use PHPCompiler\ext\standard\array_search;
use PHPCompiler\ext\standard\array_values;
use PHPCompiler\ext\standard\array_count;
use PHPCompiler\ext\standard\in_array;
use PHPCompiler\ext\standard\base64_encode;
use PHPCompiler\ext\standard\base_convert_;
use PHPCompiler\ext\standard\basename;
use PHPCompiler\ext\standard\bin2hex;
use PHPCompiler\ext\standard\bindec;
use PHPCompiler\ext\standard\checkdate;
use PHPCompiler\ext\standard\chr;
use PHPCompiler\ext\standard\chunk_split;
use PHPCompiler\ext\standard\class_exists_;
use PHPCompiler\ext\standard\class_implements_;
use PHPCompiler\ext\standard\class_parents_;
use PHPCompiler\ext\standard\class_uses_;
use PHPCompiler\ext\standard\cli_get_process_title;
use PHPCompiler\ext\standard\connection_aborted;
use PHPCompiler\ext\standard\connection_status;
use PHPCompiler\ext\standard\convert_uuencode;
use PHPCompiler\ext\standard\count_chars;
use PHPCompiler\ext\standard\crc32;
use PHPCompiler\ext\standard\date;
use PHPCompiler\ext\standard\date_default_timezone_get;
use PHPCompiler\ext\standard\date_get_last_errors;
use PHPCompiler\ext\standard\date_parse;
use PHPCompiler\ext\standard\date_parse_from_format;
use PHPCompiler\ext\standard\date_sun_info;
use PHPCompiler\ext\standard\decbin;
use PHPCompiler\ext\standard\dechex;
use PHPCompiler\ext\standard\decoct;
use PHPCompiler\ext\standard\defined_;
use PHPCompiler\ext\standard\dirname;
use PHPCompiler\ext\standard\escapeshellarg;
use PHPCompiler\ext\standard\escapeshellcmd;
use PHPCompiler\ext\standard\error_get_last;
use PHPCompiler\ext\standard\error_reporting;
use PHPCompiler\ext\standard\explode;
use PHPCompiler\ext\standard\extension_loaded;
use PHPCompiler\ext\standard\enum_exists_;
use PHPCompiler\ext\standard\fdiv;
use PHPCompiler\ext\standard\fmax;
use PHPCompiler\ext\standard\fmin;
use PHPCompiler\ext\standard\floatval;
use PHPCompiler\ext\standard\function_exists;
use PHPCompiler\ext\standard\get_mangled_object_vars_;
use PHPCompiler\ext\standard\get_object_vars_;
use PHPCompiler\ext\standard\get_class_methods_;
use PHPCompiler\ext\standard\get_class_;
use PHPCompiler\ext\standard\get_current_user;
use PHPCompiler\ext\standard\get_debug_type;
use PHPCompiler\ext\standard\get_declared_classes_;
use PHPCompiler\ext\standard\get_declared_interfaces_;
use PHPCompiler\ext\standard\get_declared_traits_;
use PHPCompiler\ext\standard\get_defined_constants_;
use PHPCompiler\ext\standard\get_defined_functions_;
use PHPCompiler\ext\standard\get_include_path;
use PHPCompiler\ext\standard\get_included_files_;
use PHPCompiler\ext\standard\get_loaded_extensions;
use PHPCompiler\ext\standard\get_parent_class_;
use PHPCompiler\ext\standard\getcwd_;
use PHPCompiler\ext\standard\getdate;
use PHPCompiler\ext\standard\gethostname;
use PHPCompiler\ext\standard\get_html_translation_table;
use PHPCompiler\ext\standard\getlastmod;
use PHPCompiler\ext\standard\getmygid;
use PHPCompiler\ext\standard\getmyinode;
use PHPCompiler\ext\standard\getmypid;
use PHPCompiler\ext\standard\getmyuid;
use PHPCompiler\ext\standard\getrandmax;
use PHPCompiler\ext\standard\getrusage;
use PHPCompiler\ext\standard\gc_enabled;
use PHPCompiler\ext\standard\gc_status;
use PHPCompiler\ext\standard\gettype;
use PHPCompiler\ext\standard\gettimeofday;
use PHPCompiler\ext\standard\gmdate;
use PHPCompiler\ext\standard\gmmktime;
use PHPCompiler\ext\hash\hash_algos;
use PHPCompiler\ext\standard\hash_;
use PHPCompiler\ext\standard\hash_equals;
use PHPCompiler\ext\standard\hash_hmac;
use PHPCompiler\ext\standard\hash_hmac_algos;
use PHPCompiler\ext\standard\headers_list;
use PHPCompiler\ext\standard\headers_sent;
use PHPCompiler\ext\standard\hebrev;
use PHPCompiler\ext\standard\hexdec;
use PHPCompiler\ext\standard\hrtime;
use PHPCompiler\ext\standard\html_entity_decode;
use PHPCompiler\ext\standard\htmlentities;
use PHPCompiler\ext\standard\htmlspecialchars;
use PHPCompiler\ext\standard\htmlspecialchars_decode;
use PHPCompiler\ext\standard\http_get_last_response_headers;
use PHPCompiler\ext\standard\http_response_code;
use PHPCompiler\ext\standard\idate;
use PHPCompiler\ext\standard\ignore_user_abort;
use PHPCompiler\ext\standard\implode;
use PHPCompiler\ext\standard\int_max;
use PHPCompiler\ext\standard\int_min;
use PHPCompiler\ext\standard\intval;
use PHPCompiler\ext\standard\inet_ntop;
use PHPCompiler\ext\standard\inet_pton;
use PHPCompiler\ext\standard\interface_exists_;
use PHPCompiler\ext\standard\ip2long;
use PHPCompiler\ext\standard\is_a_;
use PHPCompiler\ext\standard\is_subclass_of_;
use PHPCompiler\ext\standard\json_last_error_;
use PHPCompiler\ext\standard\json_last_error_msg_;
use PHPCompiler\ext\standard\levenshtein;
use PHPCompiler\ext\standard\localeconv;
use PHPCompiler\ext\standard\localtime;
use PHPCompiler\ext\standard\long2ip;
use PHPCompiler\ext\standard\memory_get_peak_usage;
use PHPCompiler\ext\standard\memory_get_usage;
use PHPCompiler\ext\standard\method_exists_;
use PHPCompiler\ext\standard\md5;
use PHPCompiler\ext\standard\metaphone;
use PHPCompiler\ext\standard\microtime;
use PHPCompiler\ext\standard\mktime;
use PHPCompiler\ext\standard\mt_getrandmax;
use PHPCompiler\ext\standard\nl2br;
use PHPCompiler\ext\standard\number_format;
use PHPCompiler\ext\standard\ob_get_contents;
use PHPCompiler\ext\standard\ob_get_length;
use PHPCompiler\ext\standard\ob_get_level;
use PHPCompiler\ext\standard\ob_list_handlers;
use PHPCompiler\ext\standard\octdec;
use PHPCompiler\ext\standard\ord;
use PHPCompiler\ext\standard\parse_url;
use PHPCompiler\ext\standard\pathinfo;
use PHPCompiler\ext\standard\php_ini_loaded_file;
use PHPCompiler\ext\standard\php_ini_scanned_files;
use PHPCompiler\ext\standard\php_sapi_name;
use PHPCompiler\ext\standard\php_uname;
use PHPCompiler\ext\standard\phpversion;
use PHPCompiler\ext\standard\preg_last_error_;
use PHPCompiler\ext\standard\preg_last_error_msg_;
use PHPCompiler\ext\standard\property_exists_;
use PHPCompiler\ext\standard\pi;
use PHPCompiler\ext\standard\pow;
use PHPCompiler\ext\standard\preg_quote;
use PHPCompiler\ext\standard\printf_;
use PHPCompiler\ext\standard\quoted_printable_decode;
use PHPCompiler\ext\standard\quoted_printable_encode;
use PHPCompiler\ext\standard\quotemeta;
use PHPCompiler\ext\standard\range;
use PHPCompiler\ext\standard\rawurldecode;
use PHPCompiler\ext\standard\rawurlencode;
use PHPCompiler\ext\standard\session_status_;
use PHPCompiler\ext\standard\sha1;
use PHPCompiler\ext\standard\similar_text;
use PHPCompiler\ext\standard\soundex;
use PHPCompiler\ext\standard\spl_autoload_functions;
use PHPCompiler\ext\standard\spl_object_hash;
use PHPCompiler\ext\standard\spl_object_id;
use PHPCompiler\ext\standard\sprintf_;
use PHPCompiler\ext\standard\sqrt;
use PHPCompiler\ext\standard\stream_get_filters;
use PHPCompiler\ext\standard\stream_get_transports;
use PHPCompiler\ext\standard\stream_get_wrappers;
use PHPCompiler\ext\standard\str_contains;
use PHPCompiler\ext\standard\str_ends_with;
use PHPCompiler\ext\standard\str_getcsv;
use PHPCompiler\ext\standard\str_ireplace;
use PHPCompiler\ext\standard\str_pad;
use PHPCompiler\ext\standard\str_repeat;
use PHPCompiler\ext\standard\str_replace;
use PHPCompiler\ext\standard\str_rot13;
use PHPCompiler\ext\standard\str_split;
use PHPCompiler\ext\standard\str_starts_with;
use PHPCompiler\ext\standard\str_word_count;
use PHPCompiler\ext\standard\strip_tags;
use PHPCompiler\ext\standard\strcasecmp;
use PHPCompiler\ext\standard\strcmp;
use PHPCompiler\ext\standard\string_trim;
use PHPCompiler\ext\standard\stripcslashes;
use PHPCompiler\ext\standard\strpbrk;
use PHPCompiler\ext\standard\strpos;
use PHPCompiler\ext\standard\strtolower;
use PHPCompiler\ext\standard\strtotime;
use PHPCompiler\ext\standard\strtr;
use PHPCompiler\ext\standard\strval;
use PHPCompiler\ext\standard\substr;
use PHPCompiler\ext\standard\substr_replace;
use PHPCompiler\ext\standard\sys_get_temp_dir;
use PHPCompiler\ext\standard\time;
use PHPCompiler\ext\standard\timezone_abbreviations_list;
use PHPCompiler\ext\standard\timezone_identifiers_list;
use PHPCompiler\ext\standard\timezone_name_from_abbr;
use PHPCompiler\ext\standard\timezone_version_get;
use PHPCompiler\ext\standard\trait_exists_;
use PHPCompiler\ext\standard\ucwords;
use PHPCompiler\ext\standard\urldecode;
use PHPCompiler\ext\standard\urlencode;
use PHPCompiler\ext\standard\version_compare;
use PHPCompiler\ext\standard\vsprintf;
use PHPCompiler\ext\standard\wordwrap;
use PHPCompiler\ext\standard\zend_version;
use PHPCompiler\ext\standard\boolval;
use PHPCompiler\ext\types\is_type;
use PHPCompiler\ext\types\strlen;
use PHPCompiler\ext\calendar\cal_days_in_month;
use PHPCompiler\ext\calendar\cal_from_jd;
use PHPCompiler\ext\calendar\cal_info;
use PHPCompiler\ext\calendar\cal_to_jd;
use PHPCompiler\ext\calendar\easter_date;
use PHPCompiler\ext\calendar\easter_days;
use PHPCompiler\ext\calendar\frenchtojd;
use PHPCompiler\ext\calendar\gregoriantojd;
use PHPCompiler\ext\calendar\jddayofweek;
use PHPCompiler\ext\calendar\jdmonthname;
use PHPCompiler\ext\calendar\jdtofrench;
use PHPCompiler\ext\calendar\jdtogregorian;
use PHPCompiler\ext\calendar\jdtojewish;
use PHPCompiler\ext\calendar\jdtojulian;
use PHPCompiler\ext\calendar\jdtounix;
use PHPCompiler\ext\calendar\jewishtojd;
use PHPCompiler\ext\calendar\juliantojd;
use PHPCompiler\ext\calendar\unixtojd;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\DiscardedPureCallElision;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value\Function_ as LlvmFunction;

/** @group aot-lint */
final class DiscardedPureCallElisionTest extends TestCase
{
    public function testElidesDiscardedStrlenWithCompileTimeString(): void
    {
        $context = $this->makeContext();
        $builtin = new strlen();
        $arg = $this->makeStringVar('hallo');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedStrlenWithTypedStringSlot(): void
    {
        $context = $this->makeContext();
        $builtin = new strlen();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideStrlenOnNativeLong(): void
    {
        // Soft strlen(int) emits deprecate / coercion — must not drop (#36386).
        $context = $this->makeContext();
        $builtin = new strlen();
        $arg = $this->makeNativeLongVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedOrdWithCompileTimeString(): void
    {
        $context = $this->makeContext();
        $builtin = new ord();
        $arg = $this->makeStringVar('A');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedOrdWithTypedStringSlot(): void
    {
        $context = $this->makeContext();
        $builtin = new ord();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideOrdOnNativeLong(): void
    {
        // Soft ord(int) → string deprecate/coerce — must not drop (#36386).
        $context = $this->makeContext();
        $builtin = new ord();
        $arg = $this->makeNativeLongVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedChrOnNativeLong(): void
    {
        $context = $this->makeContext();
        $builtin = new chr();
        $arg = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideChrOnNull(): void
    {
        // PHP 8.1+ deprecates chr(null) — must keep the call (#36386).
        $context = $this->makeContext();
        $builtin = new chr();
        $arg = $this->makeNullVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedIsIntPredicate(): void
    {
        $context = $this->makeContext();
        $builtin = new is_type('is_int', VmVariable::TYPE_INTEGER);
        $arg = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedIsStringPredicateOnValueBox(): void
    {
        $context = $this->makeContext();
        $builtin = new is_type('is_string', VmVariable::TYPE_STRING);
        $arg = $this->makeValueBoxVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedGettype(): void
    {
        $context = $this->makeContext();
        $builtin = new gettype();
        $arg = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedCtypeDigitOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new \PHPCompiler\ext\ctype\ctype_digit();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedCtypeAlnumOnLiteralString(): void
    {
        $context = $this->makeContext();
        $builtin = new \PHPCompiler\ext\ctype\ctype_alnum();
        $arg = $this->makeStringVar('Ab9');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideCtypeOnNativeLong(): void
    {
        // ctype_fallback deprecates int args (#19717) — must keep live (#36386).
        $context = $this->makeContext();
        $builtin = new \PHPCompiler\ext\ctype\ctype_digit();
        $arg = $this->makeNativeLongVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideCtypeOnNull(): void
    {
        // ctype_fallback deprecates null (#20611) — must keep live (#36386).
        $context = $this->makeContext();
        $builtin = new \PHPCompiler\ext\ctype\ctype_space();
        $arg = $this->makeNullVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedStrtolowerOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new strtolower();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedTrimOnLiteralString(): void
    {
        $context = $this->makeContext();
        $builtin = new string_trim();
        $arg = $this->makeStringVar('  x  ');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideStrtolowerOnNativeLong(): void
    {
        // Soft strtolower(int) coerces — keep live (#36386).
        $context = $this->makeContext();
        $builtin = new strtolower();
        $arg = $this->makeNativeLongVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedCountOnTypedHashtable(): void
    {
        $context = $this->makeContext();
        $builtin = new array_count();
        $arg = $this->makeHashtableVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedSizeofOnTypedHashtable(): void
    {
        $context = $this->makeContext();
        $builtin = new array_count('sizeof');
        $arg = $this->makeHashtableVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideCountOnValueBox(): void
    {
        // Countable::count() / TypeError paths must stay live (#36386).
        $context = $this->makeContext();
        $builtin = new array_count();
        $arg = $this->makeValueBoxVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideCountOnNull(): void
    {
        $context = $this->makeContext();
        $builtin = new array_count();
        $arg = $this->makeNullVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedAbsOnNativeLong(): void
    {
        $context = $this->makeContext();
        $builtin = new abs();
        $arg = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedPowOnNativeDoubles(): void
    {
        $context = $this->makeContext();
        $builtin = new pow();
        $base = $this->makeNativeDoubleVar();
        $exp = $this->makeNativeDoubleVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$base, $exp]));
    }

    public function testElidesDiscardedFdivOnNativeDoubles(): void
    {
        $context = $this->makeContext();
        $builtin = new fdiv();
        $num = $this->makeNativeDoubleVar();
        $den = $this->makeNativeDoubleVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$num, $den]));
    }

    public function testDoesNotElidePowOnNull(): void
    {
        // Match abs/sqrt: soft-null numeric path stays live for discarded math (#36386).
        $context = $this->makeContext();
        $builtin = new pow();
        $base = $this->makeNullVar();
        $exp = $this->makeNativeLongVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$base, $exp]));
    }

    public function testElidesDiscardedUcwordsOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new ucwords();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedBin2hexOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new bin2hex();
        $arg = $this->makeStringVar('ab');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedAddslashesOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new addslashes();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideUcwordsOnNull(): void
    {
        $context = $this->makeContext();
        $builtin = new ucwords();
        $arg = $this->makeNullVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedUrlencodeOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new urlencode();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedRawurlencodeOnLiteral(): void
    {
        $context = $this->makeContext();
        $builtin = new rawurlencode();
        $arg = $this->makeStringVar('a b');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedUrldecodeOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new urldecode();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedRawurldecodeOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new rawurldecode();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedStrRot13OnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new str_rot13();
        $arg = $this->makeStringVar('Hello');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedQuotemetaOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new quotemeta();
        $arg = $this->makeStringVar('a.b');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideUrlencodeOnNull(): void
    {
        // Soft-null urlencode deprecate — must keep the call (#36386).
        $context = $this->makeContext();
        $builtin = new urlencode();
        $arg = $this->makeNullVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideStrRot13OnNativeLong(): void
    {
        $context = $this->makeContext();
        $builtin = new str_rot13();
        $arg = $this->makeNativeLongVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedCrc32OnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new crc32();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedMd5OnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new md5();
        $arg = $this->makeStringVar('ab');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedSha1OnLiteral(): void
    {
        $context = $this->makeContext();
        $builtin = new sha1();
        $arg = $this->makeStringVar('xy');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedBase64EncodeOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new base64_encode();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedSoundexOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new soundex();
        $arg = $this->makeStringVar('Euler');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedMetaphoneOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new metaphone();
        $arg = $this->makeStringVar('programming');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedConvertUuencodeOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new convert_uuencode();
        $arg = $this->makeStringVar('hi');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedHebrevOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new hebrev();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedMd5WithBoolBinaryArg(): void
    {
        // Optional $binary is Z_PARAM_BOOL — typed bool/long is side-effect-free (#36386).
        $context = $this->makeContext();
        $builtin = new md5();
        $str = $this->makeStringVar('ab');
        $raw = $this->makeNativeBoolVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$str, $raw]));
    }

    public function testDoesNotElideCrc32OnNull(): void
    {
        $context = $this->makeContext();
        $builtin = new crc32();
        $arg = $this->makeNullVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedSqrtOnNativeDouble(): void
    {
        $context = $this->makeContext();
        $builtin = new sqrt();
        $arg = $this->makeNativeDoubleVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideAbsOnNull(): void
    {
        // PHP 8.1+ deprecates abs(null) — must keep the call (#36386).
        $context = $this->makeContext();
        $builtin = new abs();
        $arg = $this->makeNullVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideSqrtOnValueBox(): void
    {
        $context = $this->makeContext();
        $builtin = new sqrt();
        $arg = $this->makeValueBoxVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedHtmlspecialcharsOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new htmlspecialchars();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedHtmlspecialcharsWithTypedFlags(): void
    {
        // htmlspecialchars($s, ENT_QUOTES) is the common web form — flags are Z_PARAM_LONG.
        $context = $this->makeContext();
        $builtin = new htmlspecialchars();
        $str = $this->makeStringVar(null);
        $flags = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$str, $flags]));
    }

    public function testElidesDiscardedHtmlspecialcharsWithNullEncoding(): void
    {
        // Z_PARAM_STR_OR_NULL encoding — null is not a soft-string deprecate.
        $context = $this->makeContext();
        $builtin = new htmlspecialchars();
        $str = $this->makeStringVar('<a>');
        $flags = $this->makeNativeLongVar();
        $enc = $this->makeNullVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$str, $flags, $enc]));
    }

    public function testDoesNotElideHtmlspecialcharsOnNullString(): void
    {
        $context = $this->makeContext();
        $builtin = new htmlspecialchars();
        $arg = $this->makeNullVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedHtmlentitiesOnLiteral(): void
    {
        $context = $this->makeContext();
        $builtin = new htmlentities();
        $arg = $this->makeStringVar('&');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedHtmlspecialcharsDecodeWithFlags(): void
    {
        $context = $this->makeContext();
        $builtin = new htmlspecialchars_decode();
        $str = $this->makeStringVar(null);
        $flags = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$str, $flags]));
    }

    public function testElidesDiscardedHtmlEntityDecodeOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new html_entity_decode();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedNl2brWithBoolFlag(): void
    {
        $context = $this->makeContext();
        $builtin = new nl2br();
        $str = $this->makeStringVar(null);
        $xhtml = $this->makeNativeBoolVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$str, $xhtml]));
    }

    public function testElidesDiscardedPregQuoteWithDelimiter(): void
    {
        $context = $this->makeContext();
        $builtin = new preg_quote();
        $str = $this->makeStringVar(null);
        $delim = $this->makeStringVar('/');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$str, $delim]));
    }

    public function testElidesDiscardedEscapeshellargOnTypedString(): void
    {
        $context = $this->makeContext();
        $builtin = new escapeshellarg();
        $arg = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesDiscardedEscapeshellcmdOnLiteral(): void
    {
        $context = $this->makeContext();
        $builtin = new escapeshellcmd();
        $arg = $this->makeStringVar('echo hi');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testDoesNotElideNl2brOnNull(): void
    {
        $context = $this->makeContext();
        $builtin = new nl2br();
        $arg = $this->makeNullVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$arg]));
    }

    public function testElidesRegisteredVoidNativeWithCompileTimeStringArg(): void
    {
        $context = $this->makeContext();
        $context->discardedCallElisionVoidNatives['hallo'] = true;
        $native = $this->makeVoidNative('hallo', [VmVariable::TYPE_STRING]);
        $arg = $this->makeStringVar('hallo');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $native, [0 => $arg]));
    }

    public function testDoesNotElideVoidNativeWithoutRegistryEntry(): void
    {
        $context = $this->makeContext();
        $native = $this->makeVoidNative('hallo', [VmVariable::TYPE_STRING]);
        $arg = $this->makeStringVar('hallo');

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $native, [0 => $arg]));
    }

    public function testEffectFreeVoidCalleeBodyAllowsRecvAndReturnVoidOnly(): void
    {
        $block = new Block(null);
        $block->addOpCode(new OpCode(OpCode::TYPE_ARG_RECV));
        $block->addOpCode(new OpCode(OpCode::TYPE_RETURN_VOID));

        $this->assertTrue(Block::isEffectFreeVoidCalleeBody($block));
    }

    public function testEffectFreeVoidCalleeBodyRejectsEcho(): void
    {
        $block = new Block(null);
        $block->addOpCode(new OpCode(OpCode::TYPE_ECHO));

        $this->assertFalse(Block::isEffectFreeVoidCalleeBody($block));
    }

    public function testElidesDiscardedSubstrOnTypedStringAndLong(): void
    {
        $context = $this->makeContext();
        $builtin = new substr();
        $s = $this->makeStringVar(null);
        $i = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$s, $i]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$s, $i, $i]));
    }

    public function testDoesNotElideSubstrOnNullOffset(): void
    {
        // PHP 8.1+ deprecates substr($s, null) — keep live (#36386).
        $context = $this->makeContext();
        $builtin = new substr();
        $s = $this->makeStringVar('hi');
        $null = $this->makeNullVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$s, $null]));
    }

    public function testElidesDiscardedStrRepeatOnTypedArgs(): void
    {
        $context = $this->makeContext();
        $builtin = new str_repeat();
        $s = $this->makeStringVar('x');
        $n = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$s, $n]));
    }

    public function testElidesDiscardedStrcmpOnTypedStrings(): void
    {
        $context = $this->makeContext();
        $a = $this->makeStringVar(null);
        $b = $this->makeStringVar('y');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new strcmp(), [$a, $b]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new strcasecmp(), [$a, $b]));
    }

    public function testElidesDiscardedStrposOnTypedStrings(): void
    {
        $context = $this->makeContext();
        $builtin = new strpos();
        $hay = $this->makeStringVar(null);
        $needle = $this->makeStringVar('e');
        $off = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$hay, $needle]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, $builtin, [$hay, $needle, $off]));
    }

    public function testDoesNotElideStrposWithIntNeedle(): void
    {
        // PHP 8 deprecates int needles — must keep the call (#36386).
        $context = $this->makeContext();
        $builtin = new strpos();
        $hay = $this->makeStringVar('abc');
        $needle = $this->makeNativeLongVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$hay, $needle]));
    }

    public function testElidesDiscardedStrContainsFamilyOnTypedStrings(): void
    {
        // php-src string.c PHP_FUNCTION(str_contains|str_starts_with|str_ends_with)
        // — Z_PARAM_STR ×2; soft null stays live (#36386).
        $context = $this->makeContext();
        $hay = $this->makeStringVar(null);
        $needle = $this->makeStringVar('e');

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new str_contains(), [$hay, $needle]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new str_starts_with(), [$hay, $needle]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new str_ends_with(), [$hay, $needle]));
    }

    public function testDoesNotElideStrContainsOnNullHaystack(): void
    {
        $context = $this->makeContext();
        $null = $this->makeNullVar();
        $needle = $this->makeStringVar('x');

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, new str_contains(), [$null, $needle]));
        $this->assertFalse(DiscardedPureCallElision::tryElide($context, new str_starts_with(), [$null, $needle]));
        $this->assertFalse(DiscardedPureCallElision::tryElide($context, new str_ends_with(), [$null, $needle]));
    }

    public function testElidesDiscardedStrPadOnTypedArgs(): void
    {
        // php-src string.c PHP_FUNCTION(str_pad) — Z_PARAM_STR + LONG [+ STR + LONG]
        $context = $this->makeContext();
        $s = $this->makeStringVar(null);
        $len = $this->makeNativeLongVar();
        $pad = $this->makeStringVar(' ');
        $type = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new str_pad(), [$s, $len]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new str_pad(), [$s, $len, $pad]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new str_pad(), [$s, $len, $pad, $type]));
    }

    public function testElidesDiscardedChunkSplitWordwrapStrSplit(): void
    {
        $context = $this->makeContext();
        $s = $this->makeStringVar('abcdef');
        $n = $this->makeNativeLongVar();
        $sep = $this->makeStringVar("\n");

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new chunk_split(), [$s]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new chunk_split(), [$s, $n]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new chunk_split(), [$s, $n, $sep]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new wordwrap(), [$s]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new wordwrap(), [$s, $n, $sep]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new str_split(), [$s]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new str_split(), [$s, $n]));
    }

    public function testElidesDiscardedExplodeOnTypedStrings(): void
    {
        $context = $this->makeContext();
        $delim = $this->makeStringVar(',');
        $s = $this->makeStringVar(null);
        $limit = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new explode(), [$delim, $s]));
        $this->assertTrue(DiscardedPureCallElision::tryElide($context, new explode(), [$delim, $s, $limit]));
    }

    public function testDoesNotElideStrPadOnNullString(): void
    {
        $context = $this->makeContext();
        $null = $this->makeNullVar();
        $len = $this->makeNativeLongVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, new str_pad(), [$null, $len]));
        $this->assertFalse(DiscardedPureCallElision::tryElide($context, new chunk_split(), [$null]));
        $this->assertFalse(DiscardedPureCallElision::tryElide($context, new explode(), [$null, $this->makeStringVar('a')]));
    }

    public function testElidesDiscardedStrReplaceFamilyOnTypedStrings(): void
    {
        // php-src string.c PHP_FUNCTION(str_replace|str_ireplace|substr_replace|strtr)
        // — string forms only; &$count / array operands / two-arg strtr stay live (#36386).
        $context = $this->makeContext();
        $search = $this->makeStringVar('a');
        $replace = $this->makeStringVar('b');
        $subject = $this->makeStringVar(null);
        $offset = $this->makeNativeLongVar();
        $from = $this->makeStringVar('abc');
        $to = $this->makeStringVar('xyz');

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new str_replace(),
            [$search, $replace, $subject]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new str_ireplace(),
            [$search, $replace, $subject]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new substr_replace(),
            [$subject, $replace, $offset]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new substr_replace(),
            [$subject, $replace, $offset, $offset]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new strtr(),
            [$subject, $from, $to]
        ));
    }

    public function testDoesNotElideStrReplaceWithCountOrNull(): void
    {
        $context = $this->makeContext();
        $search = $this->makeStringVar('a');
        $replace = $this->makeStringVar('b');
        $subject = $this->makeStringVar('aa');
        $count = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $pairs = $this->makeHashtableVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new str_replace(),
            [$search, $replace, $subject, $count]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new str_ireplace(),
            [$search, $replace, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new str_replace(),
            [$search, $replace, $pairs]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strtr(),
            [$subject, $pairs]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new substr_replace(),
            [$null, $replace, $this->makeNativeLongVar()]
        ));
    }

    public function testElidesDiscardedAddcslashesStripcslashesStrpbrkOnTypedStrings(): void
    {
        // php-src string.c PHP_FUNCTION(addcslashes|stripcslashes|strpbrk) —
        // Z_PARAM_STR family; soft-null stays live (#36386).
        $context = $this->makeContext();
        $s = $this->makeStringVar(null);
        $chars = $this->makeStringVar('A..z');
        $lit = $this->makeStringVar('hello');

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new addcslashes(),
            [$s, $chars]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new addcslashes(),
            [$lit, $chars]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new stripcslashes(),
            [$s]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new strpbrk(),
            [$s, $chars]
        ));
    }

    public function testDoesNotElideAddcslashesStripcslashesStrpbrkOnNull(): void
    {
        $context = $this->makeContext();
        $null = $this->makeNullVar();
        $s = $this->makeStringVar('x');
        $chars = $this->makeStringVar('a');

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new addcslashes(),
            [$null, $chars]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new addcslashes(),
            [$s, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new stripcslashes(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strpbrk(),
            [$null, $chars]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strpbrk(),
            [$s, $null]
        ));
    }

    public function testElidesDiscardedQuotedPrintableBasenameDirname(): void
    {
        // php-src quot_print.c / basename.c / file.c — Z_PARAM_STR family;
        // dirname levels must be compile-time ≥1 (ValueError otherwise) (#36386).
        $context = $this->makeContext();
        $s = $this->makeStringVar(null);
        $lit = $this->makeStringVar('/a/b.php');
        $suffix = $this->makeStringVar('.php');
        $levelsOk = $this->makeCompileTimeLongVar(2);
        $levelsBad = $this->makeCompileTimeLongVar(0);
        $levelsUnknown = $this->makeNativeLongVar();
        $binary = $this->makeNativeBoolVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new quoted_printable_encode(),
            [$s]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new quoted_printable_decode(),
            [$lit]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new basename(),
            [$lit]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new basename(),
            [$lit, $suffix]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new dirname(),
            [$lit]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new dirname(),
            [$lit, $levelsOk]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new md5(),
            [$s, $binary]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new sha1(),
            [$s, $binary]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new metaphone(),
            [$s, $levelsOk]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hebrev(),
            [$s, $levelsOk]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new dirname(),
            [$lit, $levelsBad]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new dirname(),
            [$lit, $levelsUnknown]
        ));
        $null = $this->makeNullVar();
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new quoted_printable_encode(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new basename(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new dirname(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new md5(),
            [$s, $null]
        ));
    }

    public function testElidesDiscardedLevenshteinStrGetcsvNumberFormat(): void
    {
        // php-src levenshtein.c / file.c str_getcsv / number_format.c (#36386).
        $context = $this->makeContext();
        $a = $this->makeStringVar('kitten');
        $b = $this->makeStringVar('sitting');
        $cost = $this->makeNativeLongVar();
        $csv = $this->makeStringVar('a,b');
        $sep = $this->makeStringVar(',');
        $enc = $this->makeStringVar('"');
        $esc = $this->makeStringVar('\\');
        $num = $this->makeNativeDoubleVar();
        $decimals = $this->makeNativeLongVar();
        $dot = $this->makeStringVar('.');
        $comma = $this->makeStringVar(',');
        $null = $this->makeNullVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new levenshtein(),
            [$a, $b]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new levenshtein(),
            [$a, $b, $cost, $cost, $cost]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new str_getcsv(),
            [$csv, $sep, $enc, $esc]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new number_format(),
            [$num]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new number_format(),
            [$num, $decimals, $dot, $comma]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new number_format(),
            [$num, $decimals, $null, $null]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new levenshtein(),
            [$a, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new str_getcsv(),
            [$csv]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new str_getcsv(),
            [$csv, $sep, $enc]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new number_format(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new number_format(),
            [$num, $null]
        ));
    }

    public function testElidesDiscardedSimilarTextAndScalarCasts(): void
    {
        // php-src string.c similar_text / type.c + basic_functions.c casts (#36386).
        $context = $this->makeContext();
        $a = $this->makeStringVar('hello');
        $b = $this->makeStringVar('hallo');
        $long = $this->makeNativeLongVar();
        $dbl = $this->makeNativeDoubleVar();
        $bool = $this->makeNativeBoolVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $ht = $this->makeHashtableVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new similar_text(),
            [$a, $b]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new intval(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new intval(),
            [$a, $long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new floatval(),
            [$dbl]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new floatval('doubleval'),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new boolval(),
            [$bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new boolval(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new strval(),
            [$a]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new strval(),
            [$null]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new similar_text(),
            [$a, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new similar_text(),
            [$a, $b, $dbl]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new intval(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new intval(),
            [$long, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strval(),
            [$ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strval(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new boolval(),
            [$box]
        ));
    }

    public function testElidesDiscardedBaseConvertPiAndGetDebugType(): void
    {
        // php-src math.c base converts / pi + type.c get_debug_type (#36386).
        $context = $this->makeContext();
        $str = $this->makeStringVar('ff');
        $long = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new decbin(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new dechex(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new decoct(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new bindec(),
            [$str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hexdec(),
            [$str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new octdec(),
            [$this->makeStringVar('77')]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new pi(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_debug_type(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_debug_type(),
            [$str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_debug_type(),
            [$box]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new decbin(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new decbin(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hexdec(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hexdec(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new bindec(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new pi(),
            [$long]
        ));
    }

    public function testElidesDiscardedBaseConvertInetAndVersionCompare(): void
    {
        // php-src math.c base_convert + basic_functions.c ip2long/long2ip +
        // versioning.c version_compare (#36386).
        $context = $this->makeContext();
        $str = $this->makeStringVar('ff');
        $ip = $this->makeStringVar('127.0.0.1');
        $ver = $this->makeStringVar('8.2.0');
        $long = $this->makeNativeLongVar();
        $from = $this->makeCompileTimeLongVar(16);
        $to = $this->makeCompileTimeLongVar(10);
        $badBase = $this->makeCompileTimeLongVar(1);
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $opLt = $this->makeStringVar('<');
        $opBad = $this->makeStringVar('nope');

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new base_convert_(),
            [$str, $from, $to]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new ip2long(),
            [$ip]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new long2ip(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new version_compare(),
            [$ver, $ver]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new version_compare(),
            [$ver, $ver, $opLt]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new version_compare(),
            [$ver, $ver, $null]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new base_convert_(),
            [$str, $long, $to]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new base_convert_(),
            [$str, $badBase, $to]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new base_convert_(),
            [$null, $from, $to]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new ip2long(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new ip2long(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new long2ip(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new version_compare(),
            [$ver, $ver, $opBad]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new version_compare(),
            [$ver, $box]
        ));
    }

    public function testElidesDiscardedInetPtonNtopAndMinMax(): void
    {
        // php-src basic_functions.c inet_pton/inet_ntop + array.c min/max +
        // math.c fmin/fmax (#36386).
        $context = $this->makeContext();
        $ip = $this->makeStringVar('127.0.0.1');
        $bin = $this->makeStringVar("\x7f\x00\x00\x01");
        $long = $this->makeNativeLongVar();
        $dbl = $this->makeNativeDoubleVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $ht = $this->makeHashtableVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new inet_pton(),
            [$ip]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new inet_ntop(),
            [$bin]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new int_min(),
            [$long, $long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new int_max(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new fmin(),
            [$dbl, $dbl]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new fmax(),
            [$dbl, $long]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new inet_pton(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new inet_pton(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new inet_ntop(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new int_min(),
            [$ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new int_min(),
            [$null, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new int_max(),
            [$box, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new fmin(),
            [$dbl]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new fmax(),
            [$null, $dbl]
        ));
    }

    public function testDiscardedCheckdateAndHashEqualsElideOnTypedArgs(): void
    {
        $context = $this->makeContext();
        $long = $this->makeNativeLongVar();
        $str = $this->makeStringVar('abc');
        $lit = $this->makeStringVar('xyz');
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $ht = $this->makeHashtableVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new checkdate(),
            [$long, $long, $long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new checkdate(),
            [
                $this->makeCompileTimeLongVar(2),
                $this->makeCompileTimeLongVar(29),
                $this->makeCompileTimeLongVar(2024),
            ]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hash_equals(),
            [$str, $lit]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hash_equals(),
            [$this->makeStringVar('k'), $this->makeStringVar('u')]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new checkdate(),
            [$long, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new checkdate(),
            [$null, $long, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new checkdate(),
            [$box, $long, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_equals(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_equals(),
            [$null, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_equals(),
            [$box, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_equals(),
            [$ht, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_equals(),
            [$long, $str]
        ));
    }

    public function testDiscardedHashAndHashHmacElideOnKnownAlgo(): void
    {
        // php-src ext/hash/hash.c hash / hash_hmac (#36386).
        $context = $this->makeContext();
        $algoSha = $this->makeStringVar('sha256');
        $algoMd5 = $this->makeStringVar('MD5');
        $algoCrc = $this->makeStringVar('crc32');
        $algoUnknown = $this->makeStringVar('not-a-real-algo');
        $algoEmpty = $this->makeStringVar('');
        $algoRuntime = $this->makeStringVar(null);
        $data = $this->makeStringVar('payload');
        $key = $this->makeStringVar('secret');
        $bool = $this->makeNativeBoolVar();
        $null = $this->makeNullVar();
        $long = $this->makeNativeLongVar();
        $box = $this->makeValueBoxVar();
        $ht = $this->makeHashtableVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$algoSha, $data]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$algoMd5, $data, $bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$algoCrc, $this->makeStringVar('x'), $long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hash_hmac(),
            [$algoSha, $data, $key]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hash_hmac(),
            [$algoMd5, $data, $key, $bool]
        ));

        // Unknown / empty / runtime algo → ValueError paths stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$algoUnknown, $data]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$algoEmpty, $data]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$algoRuntime, $data]
        ));
        // crc32 is not an HMAC algo.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_hmac(),
            [$algoCrc, $data, $key]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_hmac(),
            [$algoUnknown, $data, $key]
        ));

        // Soft-null / non-string / options / wrong arity stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$algoSha]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$algoSha, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$null, $data]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$algoSha, $box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$algoSha, $data, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_(),
            [$algoSha, $data, $bool, $ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_hmac(),
            [$algoSha, $data]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_hmac(),
            [$algoSha, $null, $key]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_hmac(),
            [$algoSha, $data, $box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_hmac(),
            [$algoSha, $data, $key, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_hmac(),
            [$algoSha, $data, $key, $bool, $long]
        ));
    }

    public function testDiscardedSprintfAndVsprintfElideOnSafeFormat(): void
    {
        // php-src ext/standard/formatted_print.c sprintf / vsprintf (#36386).
        $context = $this->makeContext();
        $hello = $this->makeStringVar('hello');
        $pct = $this->makeStringVar('%%');
        $fmtSd = $this->makeStringVar('%s %d');
        $fmtS = $this->makeStringVar('%s');
        $fmtPad = $this->makeStringVar('%-10s');
        $fmtZero = $this->makeStringVar('%02d');
        $fmtPos = $this->makeStringVar('%1$s');
        $fmtStar = $this->makeStringVar('%*s');
        $fmtA = $this->makeStringVar('%a');
        $fmtBare = $this->makeStringVar('%');
        $fmtRuntime = $this->makeStringVar(null);
        $str = $this->makeStringVar('x');
        $long = $this->makeNativeLongVar();
        $bool = $this->makeNativeBoolVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $ht = $this->makeHashtableVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$hello]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$pct]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtS, $str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtSd, $str, $long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtPad, $str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtZero, $long]
        ));
        // Extra args are ignored by Zend.
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtS, $str, $long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtS, $bool]
        ));
        // vsprintf: only zero-conversion formats (array element types unknown).
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new vsprintf(),
            [$hello, $ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new vsprintf(),
            [$pct, $ht]
        ));

        // Too few args / bad formats stay live (ArgumentCountError / ValueError).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtS]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtSd, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtPos, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtStar, $long, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtA, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtBare]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtRuntime, $str]
        ));
        // Soft-null / object box / array value args stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtS, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtS, $box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new sprintf_(),
            [$fmtS, $ht]
        ));
        // vsprintf with conversions stays live (element count / __toString).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new vsprintf(),
            [$fmtS, $ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new vsprintf(),
            [$hello]
        ));
        // printf writes stdout — never elided.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new printf_(),
            [$hello]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new printf_(),
            [$fmtS, $str]
        ));
    }

    public function testDiscardedImplodeAndJoinElideOnSafePieces(): void
    {
        // php-src ext/standard/string.c php_implode (#36386).
        $context = $this->makeContext();
        $sep = $this->makeStringVar(',');
        $sepLit = $this->makeStringVar('-');
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $ht = $this->makeHashtableVar();
        $empty = $this->makeHashtableVar();
        $empty->compileTimeEmptyArrayLiteral = true;
        $valueBoxHt = $this->makeValueBoxVar();
        $valueBoxHt->valueBoxHashtable = true;
        $native = $this->makeNativeLongArrayVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new implode(),
            [$empty]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new implode(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new implode(),
            [$sep, $ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new implode(),
            [$sep, $valueBoxHt]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new implode('join'),
            [$sepLit, $native]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new implode('join'),
            [$native]
        ));

        // Soft-null separator / non-array / array-first stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new implode(),
            [$null, $ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new implode(),
            [$ht, $sep]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new implode(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new implode(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new implode(),
            [$sep, $ht, $null]
        ));
    }

    public function testDiscardedPathinfoAndParseUrlElideOnTypedArgs(): void
    {
        $context = $this->makeContext();
        $str = $this->makeStringVar('/a/b.txt');
        $lit = $this->makeStringVar('http://example.com/x');
        $long = $this->makeNativeLongVar();
        $flags = $this->makeCompileTimeLongVar(PATHINFO_EXTENSION);
        $comp = $this->makeCompileTimeLongVar(PHP_URL_HOST);
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $ht = $this->makeHashtableVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new pathinfo(),
            [$str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new pathinfo(),
            [$str, $flags]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new pathinfo(),
            [$this->makeStringVar('/x/y.z'), $long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new parse_url(),
            [$lit]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new parse_url(),
            [$lit, $comp]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new parse_url(),
            [$str, $long]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new pathinfo(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new pathinfo(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new pathinfo(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new pathinfo(),
            [$str, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new pathinfo(),
            [$str, $flags, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new parse_url(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new parse_url(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new parse_url(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new parse_url(),
            [$ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new parse_url(),
            [$lit, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new parse_url(),
            [$lit, $comp, $long]
        ));
    }

    public function testDiscardedFunctionExistsAndExtensionLoadedElideOnTypedArgs(): void
    {
        $context = $this->makeContext();
        $str = $this->makeStringVar('strlen');
        $lit = $this->makeStringVar('standard');
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $ht = $this->makeHashtableVar();
        $long = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new function_exists(),
            [$str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new function_exists(),
            [$this->makeStringVar('array_map')]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new extension_loaded(),
            [$lit]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new extension_loaded(),
            [$this->makeStringVar('core')]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new function_exists(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new function_exists(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new function_exists(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new function_exists(),
            [$ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new function_exists(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new function_exists(),
            [$str, $lit]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new extension_loaded(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new extension_loaded(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new extension_loaded(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new extension_loaded(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new extension_loaded(),
            [$lit, $str]
        ));
    }

    public function testDiscardedMethodExistsAndPropertyExistsElideOnTypedObject(): void
    {
        $context = $this->makeContext();
        $obj = $this->makeObjectVar();
        $method = $this->makeStringVar('bump');
        $prop = $this->makeStringVar('x');
        $litMethod = $this->makeStringVar('__construct');
        $className = $this->makeStringVar('stdClass');
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $ht = $this->makeHashtableVar();
        $long = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            [$obj, $method]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            [$obj, $litMethod]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new property_exists_(),
            [$obj, $prop]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new property_exists_(),
            [$obj, $this->makeStringVar('name')]
        ));

        // String class names autoload — stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            [$className, $method]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new property_exists_(),
            [$className, $prop]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            [$obj]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            [$obj, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            [$null, $method]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            [$box, $method]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            [$ht, $method]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            [$long, $method]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            [$obj, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new method_exists_(),
            [$obj, $method, $prop]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new property_exists_(),
            [$obj, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new property_exists_(),
            [$box, $prop]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new property_exists_(),
            [$obj, $long]
        ));
    }

    public function testDiscardedClassExistsFamilyElidesOnlyWithFalseAutoload(): void
    {
        $context = $this->makeContext();
        $name = $this->makeStringVar('stdClass');
        $lit = $this->makeStringVar('Traversable');
        $false = $this->makeCompileTimeLongVar(0);
        $true = $this->makeCompileTimeLongVar(1);
        $falseBool = $this->makeNativeBoolVar();
        $falseBool->compileTimeLong = 0;
        $trueBool = $this->makeNativeBoolVar();
        $trueBool->compileTimeLong = 1;
        $dynBool = $this->makeNativeBoolVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $ht = $this->makeHashtableVar();
        $long = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$name, $false]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$lit, $falseBool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new interface_exists_(),
            [$name, $false]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new trait_exists_(),
            [$name, $falseBool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new enum_exists_(),
            [$lit, $false]
        ));

        // Default autoload=true — stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$name]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$name, $true]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$name, $trueBool]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$name, $dynBool]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$name, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$null, $false]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$box, $false]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$ht, $false]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$long, $false]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new interface_exists_(),
            [$name, $true]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new trait_exists_(),
            [$name]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new enum_exists_(),
            [$name, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_exists_(),
            [$name, $false, $lit]
        ));
    }

    public function testDiscardedObjectIntrospectElidesOnTypedObject(): void
    {
        // php-src zend_builtin_functions.c / ext/spl/php_spl.c — typed object only (#36386).
        $context = $this->makeContext();
        $obj = $this->makeObjectVar();
        $str = $this->makeStringVar('stdClass');
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $long = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_class_(),
            [$obj]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_parent_class_(),
            [$obj]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new spl_object_id(),
            [$obj]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new spl_object_hash(),
            [$obj]
        ));

        // Zero-arg / string / soft-null / value-box stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_class_(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_parent_class_(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_parent_class_(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_class_(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_class_(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new spl_object_id(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new spl_object_id(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new spl_object_hash(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_class_(),
            [$obj, $str]
        ));
    }

    public function testDiscardedIsAFamilyElidesOnTypedObject(): void
    {
        // php-src zend_builtin_functions.c — typed object subject; string subjects
        // autoload when allow_string (#36386).
        $context = $this->makeContext();
        $obj = $this->makeObjectVar();
        $str = $this->makeStringVar('stdClass');
        $bool = $this->makeNativeBoolVar();
        $long = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new is_a_(),
            [$obj, $str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new is_subclass_of_(),
            [$obj, $str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new is_a_(),
            [$obj, $str, $bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new is_subclass_of_(),
            [$obj, $str, $long]
        ));

        // String / soft-null / value-box subjects stay live; soft-null class /
        // allow_string stay live (deprecate / autoload).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new is_a_(),
            [$str, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new is_subclass_of_(),
            [$str, $str, $bool]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new is_a_(),
            [$null, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new is_a_(),
            [$box, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new is_a_(),
            [$obj, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new is_a_(),
            [$obj, $str, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new is_a_(),
            [$obj, $str, $box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new is_a_(),
            [$obj]
        ));
    }

    public function testDiscardedClassHierarchyElidesOnTypedObject(): void
    {
        // php-src class.c / basic_functions.c / spl_functions.c — typed object
        // subject; string subjects autoload (#36386).
        $context = $this->makeContext();
        $obj = $this->makeObjectVar();
        $str = $this->makeStringVar('stdClass');
        $bool = $this->makeNativeBoolVar();
        $long = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new class_parents_(),
            [$obj]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new class_implements_(),
            [$obj]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new class_uses_(),
            [$obj]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new class_parents_(),
            [$obj, $bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new class_implements_(),
            [$obj, $long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new class_uses_(),
            [$obj, $this->makeCompileTimeLongVar(0)]
        ));

        // String / soft-null / value-box subjects stay live; soft-null
        // $autoload stays live (deprecate / autoload).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_parents_(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_implements_(),
            [$str, $bool]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_uses_(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_parents_(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_parents_(),
            [$obj, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_implements_(),
            [$obj, $box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new class_uses_(),
            []
        ));
    }

    public function testDiscardedObjectVarsMethodsElidesOnTypedObject(): void
    {
        // php-src zend_builtin_functions.c / var.c — typed object only; string
        // get_class_methods autoloads (#36386).
        $context = $this->makeContext();
        $obj = $this->makeObjectVar();
        $str = $this->makeStringVar('stdClass');
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $long = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_object_vars_(),
            [$obj]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_mangled_object_vars_(),
            [$obj]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_class_methods_(),
            [$obj]
        ));

        // Soft-null / string / value-box stay live (TypeError / autoload).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_object_vars_(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_mangled_object_vars_(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_class_methods_(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_object_vars_(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_class_methods_(),
            [$obj, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_object_vars_(),
            []
        ));
    }

    public function testDiscardedZeroArgRuntimeInfoElides(): void
    {
        // php-src basic_functions.c / info.c / Zend/zend.c — arity 0 only (#36386).
        $context = $this->makeContext();
        $long = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_declared_classes_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_declared_interfaces_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_declared_traits_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_included_files_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_included_files_('get_required_files'),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new php_sapi_name(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new zend_version(),
            []
        ));

        // Excess argc stays live (ArgumentCountError).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_declared_classes_(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_included_files_(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new php_sapi_name(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new zend_version(),
            [$long]
        ));
    }

    public function testDiscardedDefinedTableRuntimeInfoElides(): void
    {
        // php-src basic_functions.c / info.c — arity 0 or typed bool (#36386).
        $context = $this->makeContext();
        $bool = $this->makeNativeBoolVar();
        $long = $this->makeNativeLongVar();
        $falseLit = $this->makeCompileTimeLongVar(0);
        $trueLit = $this->makeCompileTimeLongVar(1);
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('x');
        $box = $this->makeValueBoxVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_loaded_extensions(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_loaded_extensions(),
            [$bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_loaded_extensions(),
            [$falseLit]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_defined_constants_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_defined_constants_(),
            [$trueLit]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_defined_functions_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_defined_functions_(),
            [$long]
        ));

        // Soft-null bool stays live (deprecate).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_loaded_extensions(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_defined_constants_(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_defined_functions_(),
            [$null]
        ));
        // Non-bool coercible / excess argc stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_loaded_extensions(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_defined_constants_(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_defined_functions_(),
            [$bool, $long]
        ));
    }

    public function testDiscardedProcessIdentityElides(): void
    {
        // php-src info.c / basic_functions.c — identity reads (#36386).
        $context = $this->makeContext();
        $str = $this->makeStringVar('s');
        $ext = $this->makeStringVar('standard');
        $null = $this->makeNullVar();
        $long = $this->makeNativeLongVar();
        $box = $this->makeValueBoxVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new phpversion(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new phpversion(),
            [$ext]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new php_uname(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new php_uname(),
            [$str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new getmypid(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new getmyuid(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new getmygid(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new getmyinode(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new getlastmod(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_current_user(),
            []
        ));

        // Soft-null optional string stays live (deprecate).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new phpversion(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new php_uname(),
            [$null]
        ));
        // Non-string / excess argc stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new phpversion(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new php_uname(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new phpversion(),
            [$ext, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new getmypid(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new getmyuid(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new getmygid(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new getmyinode(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new getlastmod(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_current_user(),
            [$null]
        ));
    }

    public function testDiscardedMemoryIniRuntimeInfoElides(): void
    {
        // php-src alloc / ini / GC introspection (#36386).
        $context = $this->makeContext();
        $bool = $this->makeNativeBoolVar();
        $long = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('s');
        $box = $this->makeValueBoxVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new memory_get_usage(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new memory_get_usage(),
            [$bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new memory_get_peak_usage(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new memory_get_peak_usage(),
            [$bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new php_ini_loaded_file(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new php_ini_scanned_files(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new gc_enabled(),
            []
        ));

        // Soft-null bool stays live (deprecate / TypeError).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new memory_get_usage(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new memory_get_peak_usage(),
            [$null]
        ));
        // Non-bool / excess argc / zero-arg with args stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new memory_get_usage(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new memory_get_peak_usage(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new memory_get_usage(),
            [$bool, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new php_ini_loaded_file(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new php_ini_scanned_files(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new gc_enabled(),
            [$null]
        ));
    }

    public function testDiscardedEnvPathRequestRuntimeInfoElides(): void
    {
        // php-src file/dir/basic_functions/output/session/locale/GC (#36386).
        $context = $this->makeContext();
        $long = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('s');

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new sys_get_temp_dir(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new getcwd_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_include_path(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new ob_get_level(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new connection_status(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new connection_aborted(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new session_status_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new localeconv(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new gc_status(),
            []
        ));

        // Excess argc stays live (ArgumentCountError).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new sys_get_temp_dir(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new getcwd_(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_include_path(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new ob_get_level(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new connection_status(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new connection_aborted(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new session_status_(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new localeconv(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new gc_status(),
            [$null]
        ));
    }

    public function testDiscardedHostErrorHashObRuntimeInfoElides(): void
    {
        // php-src basic_functions / hash / output / head (#36386).
        $context = $this->makeContext();
        $long = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('s');

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new gethostname(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new error_get_last(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new getrusage(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new getrusage(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hash_algos(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hash_hmac_algos(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new ob_get_contents(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new ob_get_length(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new headers_list(),
            []
        ));

        // Soft-null getrusage mode stays live (deprecate).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new getrusage(),
            [$null]
        ));
        // Excess argc stays live (ArgumentCountError).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new gethostname(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new error_get_last(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new getrusage(),
            [$long, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_algos(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hash_hmac_algos(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new ob_get_contents(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new ob_get_length(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new headers_list(),
            [$str]
        ));
    }

    public function testDiscardedJsonPregTzStreamCliRuntimeInfoElides(): void
    {
        // php-src json / pcre / date / streams / cli_ops (#36386).
        $context = $this->makeContext();
        $long = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('s');

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new json_last_error_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new json_last_error_msg_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new preg_last_error_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new preg_last_error_msg_(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new date_default_timezone_get(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_version_get(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new stream_get_wrappers(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new stream_get_transports(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new stream_get_filters(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new cli_get_process_title(),
            []
        ));

        // Excess argc stays live (ArgumentCountError).
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new json_last_error_(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new json_last_error_msg_(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new preg_last_error_(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new preg_last_error_msg_(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_default_timezone_get(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_version_get(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new stream_get_wrappers(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new stream_get_transports(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new stream_get_filters(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cli_get_process_title(),
            [$str]
        ));
    }

    public function testDiscardedDateObHttpSplTimeGetterRuntimeInfoElides(): void
    {
        // php-src date / output / http / spl / basic_functions / head (#36386).
        $context = $this->makeContext();
        $long = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('s');

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_abbreviations_list(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_identifiers_list(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_identifiers_list(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new ob_list_handlers(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new date_get_last_errors(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new http_get_last_response_headers(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new spl_autoload_functions(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new time(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new error_reporting(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new ignore_user_abort(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new http_response_code(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new headers_sent(),
            []
        ));

        // Soft-null group / setter / by-ref / excess argc stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_identifiers_list(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_identifiers_list(),
            [$long, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_abbreviations_list(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new ob_list_handlers(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_get_last_errors(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new http_get_last_response_headers(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new spl_autoload_functions(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new time(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new error_reporting(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new ignore_user_abort(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new http_response_code(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new headers_sent(),
            [$str]
        ));
    }

    public function testDiscardedClockGetterRuntimeInfoElides(): void
    {
        // php-src microtime.c / hrtime.c (#36386).
        $context = $this->makeContext();
        $bool = $this->makeNativeBoolVar();
        $long = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('s');

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new microtime(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new microtime(),
            [$bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new microtime(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hrtime(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new hrtime(),
            [$bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new gettimeofday(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new gettimeofday(),
            [$bool]
        ));

        // Soft-null bool / excess argc stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new microtime(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hrtime(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new gettimeofday(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new microtime(),
            [$bool, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new hrtime(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new gettimeofday(),
            [$str]
        ));
    }

    public function testDiscardedCivilDateGetterAndRandmaxRuntimeInfoElides(): void
    {
        // php-src php_date.c / datetime.c / random.c (#36386).
        $context = $this->makeContext();
        $bool = $this->makeNativeBoolVar();
        $long = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('s');
        $fmt = $this->makeStringVar('Y');
        $badFmt = $this->makeStringVar('Z');
        $longFmt = $this->makeStringVar('YY');
        $typedFmt = $this->makeStringVar(null);

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new getdate(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new getdate(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new localtime(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new localtime(),
            [$long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new localtime(),
            [$long, $bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new idate(),
            [$fmt]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new idate(),
            [$fmt, $long]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new getrandmax(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new mt_getrandmax(),
            []
        ));

        // Soft-null / bad idate format / excess argc stay live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new getdate(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new localtime(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new localtime(),
            [$long, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new idate(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new idate(),
            [$badFmt]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new idate(),
            [$longFmt]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new idate(),
            [$typedFmt]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new getdate(),
            [$long, $bool]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new localtime(),
            [$long, $bool, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new idate(),
            [$fmt, $long, $bool]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new getrandmax(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new mt_getrandmax(),
            [$null]
        ));
    }

    public function testDiscardedDateGmdateMktimeElidesOnTypedArgs(): void
    {
        // php-src ext/date/php_date.c date/gmdate/mktime/gmmktime (#36386).
        $context = $this->makeContext();
        $fmt = $this->makeStringVar('Y-m-d');
        $typedFmt = $this->makeStringVar(null);
        $ts = $this->makeNativeLongVar();
        $hour = $this->makeNativeLongVar();
        $min = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('12');
        $box = $this->makeValueBoxVar();
        $obj = $this->makeObjectVar();
        $bool = $this->makeNativeBoolVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new date(),
            [$fmt]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new date(),
            [$typedFmt, $ts]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new date(),
            [$fmt, $null]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new gmdate(),
            [$fmt, $ts]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new mktime(),
            [$hour]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new mktime(),
            [$hour, $min, $null]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new gmmktime(),
            [$hour, $bool, $min]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date(),
            [$fmt, $ts, $min]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new gmdate(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new mktime(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new mktime(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new mktime(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new mktime(),
            [$hour, $obj]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new gmmktime(),
            [$hour, $min, $min, $min, $min, $min, $min]
        ));
    }

    public function testDiscardedStrtotimeAndDateParseElideOnTypedArgs(): void
    {
        // php-src ext/date/php_date.c strtotime / date_parse / date_parse_from_format (#36386).
        $context = $this->makeContext();
        $lit = $this->makeStringVar('2024-01-15');
        $typed = $this->makeStringVar(null);
        $fmt = $this->makeStringVar('Y-m-d');
        $ts = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $obj = $this->makeObjectVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new strtotime(),
            [$lit]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new strtotime(),
            [$typed, $ts]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new strtotime(),
            [$lit, $null]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new date_parse(),
            [$lit]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new date_parse(),
            [$typed]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new date_parse_from_format(),
            [$fmt, $typed]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strtotime(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strtotime(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strtotime(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strtotime(),
            [$lit, $ts, $ts]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_parse(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_parse(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_parse(),
            [$obj]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_parse(),
            [$lit, $lit]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_parse_from_format(),
            [$fmt]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_parse_from_format(),
            [$null, $typed]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_parse_from_format(),
            [$fmt, $null]
        ));
    }

    public function testDiscardedDateSunInfoAndTimezoneNameFromAbbrElideOnTypedArgs(): void
    {
        // php-src ext/date/php_date.c date_sun_info / timezone_name_from_abbr (#36386).
        $context = $this->makeContext();
        $ts = $this->makeNativeLongVar();
        $lat = $this->makeNativeDoubleVar();
        $lon = $this->makeNativeDoubleVar();
        $abbr = $this->makeStringVar('CET');
        $typedAbbr = $this->makeStringVar(null);
        $offset = $this->makeNativeLongVar();
        $isdst = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $obj = $this->makeObjectVar();
        $str = $this->makeStringVar('51.5');

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new date_sun_info(),
            [$ts, $lat, $lon]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new date_sun_info(),
            [$ts, $ts, $lon]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_name_from_abbr(),
            [$abbr]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_name_from_abbr(),
            [$typedAbbr, $offset]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_name_from_abbr(),
            [$abbr, $offset, $isdst]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_sun_info(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_sun_info(),
            [$ts, $lat]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_sun_info(),
            [$null, $lat, $lon]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_sun_info(),
            [$ts, $box, $lon]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new date_sun_info(),
            [$ts, $lat, $obj]
        ));
        // Numeric string literal is allowed by mathArgAllowsDiscardedElision —
        // keep that behaviour; soft-null / objects stay live above.
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new date_sun_info(),
            [$ts, $str, $lon]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_name_from_abbr(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_name_from_abbr(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_name_from_abbr(),
            [$abbr, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_name_from_abbr(),
            [$abbr, $offset, $isdst, $ts]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new timezone_name_from_abbr(),
            [$obj]
        ));
    }

    public function testDiscardedCalendarToJdAndCalDaysInMonthElideOnTypedArgs(): void
    {
        // php-src ext/calendar/calendar.c *tojd + cal_days_in_month (#36386).
        $context = $this->makeContext();
        $month = $this->makeNativeLongVar();
        $day = $this->makeNativeLongVar();
        $year = $this->makeNativeLongVar();
        $calGregorian = $this->makeCompileTimeLongVar(0);
        $calJulian = $this->makeCompileTimeLongVar(1);
        $calInvalid = $this->makeCompileTimeLongVar(4);
        $calRuntime = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $obj = $this->makeObjectVar();
        $str = $this->makeStringVar('3');

        foreach ([
            new gregoriantojd(),
            new juliantojd(),
            new jewishtojd(),
            new frenchtojd(),
        ] as $builtin) {
            $this->assertTrue(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$month, $day, $year]
            ));
            $this->assertTrue(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$month, $str, $year]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                []
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$month, $day]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$null, $day, $year]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$month, $box, $year]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$month, $day, $obj]
            ));
        }

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new cal_days_in_month(),
            [$calGregorian, $month, $year]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new cal_days_in_month(),
            [$calJulian, $month, $year]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_days_in_month(),
            [$calRuntime, $month, $year]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_days_in_month(),
            [$calInvalid, $month, $year]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_days_in_month(),
            [$calGregorian, $null, $year]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_days_in_month(),
            [$calGregorian, $month]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_days_in_month(),
            []
        ));
    }

    public function testDiscardedCalendarFromJdAndCalToFromJdElideOnTypedArgs(): void
    {
        // php-src ext/calendar/calendar.c jdto* + jdmonthname/jddayofweek +
        // cal_from_jd / cal_to_jd (#36386).
        $context = $this->makeContext();
        $jd = $this->makeNativeLongVar();
        $mode = $this->makeNativeLongVar();
        $month = $this->makeNativeLongVar();
        $day = $this->makeNativeLongVar();
        $year = $this->makeNativeLongVar();
        $calGregorian = $this->makeCompileTimeLongVar(0);
        $calJulian = $this->makeCompileTimeLongVar(1);
        $calInvalid = $this->makeCompileTimeLongVar(4);
        $calRuntime = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $obj = $this->makeObjectVar();
        $str = $this->makeStringVar('2440588');

        foreach ([
            new jdtogregorian(),
            new jdtojulian(),
            new jdtofrench(),
        ] as $builtin) {
            $this->assertTrue(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$jd]
            ));
            $this->assertTrue(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$str]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                []
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$jd, $mode]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$null]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$box]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$obj]
            ));
        }

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new jdmonthname(),
            [$jd, $mode]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdmonthname(),
            [$jd]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdmonthname(),
            [$null, $mode]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdmonthname(),
            [$jd, $box]
        ));

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new jddayofweek(),
            [$jd]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new jddayofweek(),
            [$jd, $mode]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jddayofweek(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jddayofweek(),
            [$jd, $mode, $day]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jddayofweek(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jddayofweek(),
            [$jd, $null]
        ));

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new cal_from_jd(),
            [$jd, $calGregorian]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new cal_from_jd(),
            [$jd, $calJulian]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_from_jd(),
            [$jd, $calRuntime]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_from_jd(),
            [$jd, $calInvalid]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_from_jd(),
            [$null, $calGregorian]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_from_jd(),
            [$jd]
        ));

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new cal_to_jd(),
            [$calGregorian, $month, $day, $year]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new cal_to_jd(),
            [$calJulian, $month, $day, $year]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_to_jd(),
            [$calRuntime, $month, $day, $year]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_to_jd(),
            [$calInvalid, $month, $day, $year]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_to_jd(),
            [$calGregorian, $null, $day, $year]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_to_jd(),
            [$calGregorian, $month, $day]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_to_jd(),
            []
        ));
    }

    public function testDiscardedCalInfoEasterJdtojewishUnixJdElideOnSafeArgs(): void
    {
        // php-src ext/calendar/{calendar,easter,cal_unix}.c leftovers (#36386).
        $context = $this->makeContext();
        $jd = $this->makeNativeLongVar();
        $mode = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $obj = $this->makeObjectVar();
        $str = $this->makeStringVar('2440588');
        $calGregorian = $this->makeCompileTimeLongVar(0);
        $calAll = $this->makeCompileTimeLongVar(-1);
        $calInvalid = $this->makeCompileTimeLongVar(4);
        $calRuntime = $this->makeNativeLongVar();
        $yearOk = $this->makeCompileTimeLongVar(2024);
        $yearLow = $this->makeCompileTimeLongVar(1969);
        $yearZero = $this->makeCompileTimeLongVar(0);
        $yearRuntime = $this->makeNativeLongVar();
        $unixEpochJd = $this->makeCompileTimeLongVar(2440588);
        $jdBeforeEpoch = $this->makeCompileTimeLongVar(2440587);
        $tsZero = $this->makeCompileTimeLongVar(0);
        $tsNeg = $this->makeCompileTimeLongVar(-1);
        $tsRuntime = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new cal_info(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new cal_info(),
            [$calGregorian]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new cal_info(),
            [$calAll]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_info(),
            [$calInvalid]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_info(),
            [$calRuntime]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_info(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new cal_info(),
            [$calGregorian, $mode]
        ));

        foreach ([new easter_days(), new easter_date()] as $builtin) {
            $this->assertTrue(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$yearOk]
            ));
            $this->assertTrue(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$yearOk, $mode]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                []
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$yearRuntime]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$yearZero]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$null]
            ));
            $this->assertFalse(DiscardedPureCallElision::tryElide(
                $context,
                $builtin,
                [$yearOk, $null]
            ));
        }
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new easter_date(),
            [$yearLow]
        ));
        // easter_days allows years before 1970; easter_date does not.
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new easter_days(),
            [$yearLow]
        ));

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new jdtojewish(),
            [$jd]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new jdtojewish(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdtojewish(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdtojewish(),
            [$jd, $mode]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdtojewish(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdtojewish(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdtojewish(),
            [$obj]
        ));

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new jdtounix(),
            [$unixEpochJd]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdtounix(),
            [$jdBeforeEpoch]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdtounix(),
            [$jd]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdtounix(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new jdtounix(),
            []
        ));

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new unixtojd(),
            [$tsZero]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new unixtojd(),
            [$tsNeg]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new unixtojd(),
            [$tsRuntime]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new unixtojd(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new unixtojd(),
            [$null]
        ));
    }

    public function testDiscardedArrayKeyEdgeElidesOnTypedArray(): void
    {
        // php-src ext/standard/array.c array_key_first/last + array_is_list (#36386).
        $context = $this->makeContext();
        $ht = $this->makeHashtableVar();
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('s');
        $long = $this->makeNativeLongVar();
        $obj = $this->makeObjectVar();
        $box = $this->makeValueBoxVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_first(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_last(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_is_list(),
            [$ht]
        ));

        $valueBoxHt = $this->makeValueBoxVar();
        $valueBoxHt->valueBoxHashtable = true;
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_first(),
            [$valueBoxHt]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_first(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_first(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_last(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_is_list(),
            [$obj]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_first(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_first(),
            [$ht, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_is_list(),
            [$ht, $null]
        ));
    }

    public function testDiscardedArrayCopyElidesOnTypedArray(): void
    {
        // php-src ext/standard/array.c array_keys/values/first/last/reverse/
        // change_key_case (#36386).
        $context = $this->makeContext();
        $ht = $this->makeHashtableVar();
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('s');
        $long = $this->makeNativeLongVar();
        $bool = $this->makeNativeBoolVar();
        $obj = $this->makeObjectVar();
        $box = $this->makeValueBoxVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_keys(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_values(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_first(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_last(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_reverse(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_reverse(),
            [$ht, $bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_change_key_case(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_change_key_case(),
            [$ht, $long]
        ));

        $valueBoxHt = $this->makeValueBoxVar();
        $valueBoxHt->valueBoxHashtable = true;
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_values(),
            [$valueBoxHt]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_first(),
            [$valueBoxHt]
        ));

        // Filtered array_keys stays live.
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_keys(),
            [$ht, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_keys(),
            [$ht, $str, $bool]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_values(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_values(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_first(),
            [$str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_last(),
            [$obj]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_reverse(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_reverse(),
            [$ht, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_change_key_case(),
            [$ht, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_values(),
            [$ht, $long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_reverse(),
            [$ht, $bool, $long]
        ));
    }

    public function testDiscardedArrayMergeDiffElidesOnTypedArray(): void
    {
        // php-src ext/standard/array.c array_merge/replace/diff/intersect (#36386).
        $context = $this->makeContext();
        $ht = $this->makeHashtableVar();
        $ht2 = $this->makeHashtableVar();
        $null = $this->makeNullVar();
        $str = $this->makeStringVar('s');
        $box = $this->makeValueBoxVar();
        $obj = $this->makeObjectVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_merge(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_merge(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_merge(),
            [$ht, $ht2]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_merge_recursive(),
            [$ht, $ht2]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_replace(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_replace(),
            [$ht, $ht2]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_replace_recursive(),
            [$ht, $ht2]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_diff(),
            [$ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_diff(),
            [$ht, $ht2]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_intersect(),
            [$ht, $ht2]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_diff_key(),
            [$ht, $ht2]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_intersect_key(),
            [$ht, $ht2]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_diff_assoc(),
            [$ht, $ht2]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_intersect_assoc(),
            [$ht, $ht2]
        ));

        $valueBoxHt = $this->makeValueBoxVar();
        $valueBoxHt->valueBoxHashtable = true;
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_merge(),
            [$valueBoxHt, $ht]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_replace(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_diff(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_intersect(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_merge(),
            [$ht, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_replace(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_diff(),
            [$ht, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_intersect(),
            [$box, $ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_diff_key(),
            [$obj, $ht]
        ));
    }

    public function testDiscardedArrayLookupElidesOnTypedHaystack(): void
    {
        // php-src ext/standard/array.c in_array / array_search (#36386).
        $context = $this->makeContext();
        $ht = $this->makeHashtableVar();
        $needle = $this->makeNativeLongVar();
        $strNeedle = $this->makeStringVar('x');
        $null = $this->makeNullVar();
        $bool = $this->makeNativeBoolVar();
        $box = $this->makeValueBoxVar();
        $obj = $this->makeObjectVar();
        $str = $this->makeStringVar('s');

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new in_array(),
            [$needle, $ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new in_array(),
            [$strNeedle, $ht, $bool]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new in_array(),
            [$null, $ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_search(),
            [$needle, $ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_search(),
            [$obj, $ht, $bool]
        ));

        $valueBoxHt = $this->makeValueBoxVar();
        $valueBoxHt->valueBoxHashtable = true;
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new in_array(),
            [$needle, $valueBoxHt]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new in_array(),
            [$needle]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new in_array(),
            [$needle, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new in_array(),
            [$needle, $str]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new in_array(),
            [$needle, $box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new in_array(),
            [$needle, $ht, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_search(),
            [$needle, $ht, $bool, $str]
        ));
    }

    public function testDiscardedArrayConstructElidesOnSafeArgs(): void
    {
        // php-src ext/standard/array.c array_pad/fill/fill_keys/column (#36386).
        $context = $this->makeContext();
        $ht = $this->makeHashtableVar();
        $keys = $this->makeHashtableVar();
        $start = $this->makeNativeLongVar();
        $lenOk = $this->makeCompileTimeLongVar(4);
        $lenHuge = $this->makeCompileTimeLongVar(1048577);
        $lenDyn = $this->makeNativeLongVar();
        $countOk = $this->makeCompileTimeLongVar(3);
        $countNeg = $this->makeCompileTimeLongVar(-1);
        $val = $this->makeStringVar('v');
        $col = $this->makeStringVar('name');
        $colLong = $this->makeNativeLongVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $obj = $this->makeObjectVar();
        $str = $this->makeStringVar('s');

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_pad(),
            [$ht, $lenOk, $val]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_fill(),
            [$start, $countOk, $val]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_fill_keys(),
            [$keys, $val]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_column(),
            [$ht, $col]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_column(),
            [$ht, $null]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_column(),
            [$ht, $colLong, $col]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_pad(),
            [$ht, $lenHuge, $val]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_pad(),
            [$ht, $lenDyn, $val]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_pad(),
            [$null, $lenOk, $val]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_pad(),
            [$ht, $lenOk, $val, $start]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_fill(),
            [$start, $countNeg, $val]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_fill(),
            [$null, $countOk, $val]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_fill_keys(),
            [$null, $val]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_fill_keys(),
            [$box, $val]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_column(),
            [$ht, $obj]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_column(),
            [$str, $col]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_column(),
            [$ht, $box]
        ));
    }

    public function testDiscardedArrayCombineAndRangeElideOnSafeArgs(): void
    {
        // php-src ext/standard/array.c array_combine / range (#36386).
        $context = $this->makeContext();
        $emptyKeys = $this->makeHashtableVar();
        $emptyKeys->compileTimeEmptyArrayLiteral = true;
        $emptyVals = $this->makeHashtableVar();
        $emptyVals->compileTimeEmptyArrayLiteral = true;
        $keysEq = $this->makeHashtableVar();
        $keysEq->compileTimeArray = ['a', 'b'];
        $valsEq = $this->makeHashtableVar();
        $valsEq->compileTimeArray = ['x', 'y'];
        $keysMismatch = $this->makeHashtableVar();
        $keysMismatch->compileTimeArray = ['a'];
        $valsMismatch = $this->makeHashtableVar();
        $valsMismatch->compileTimeArray = ['x', 'y'];
        $assocKeys = $this->makeHashtableVar();
        $assocKeys->compileTimeAssoc = ['k' => 1];
        $assocVals = $this->makeHashtableVar();
        $assocVals->compileTimeAssoc = ['v' => 2];
        $keysEmptyPack = $this->makeHashtableVar();
        $keysEmptyPack->compileTimeArray = [];
        $valsEmptyPack = $this->makeHashtableVar();
        $valsEmptyPack->compileTimeArray = [];
        $assocEmpty = $this->makeHashtableVar();
        $assocEmpty->compileTimeAssoc = [];
        $assocEmpty2 = $this->makeHashtableVar();
        $assocEmpty2->compileTimeAssoc = [];
        $ht = $this->makeHashtableVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $start = $this->makeNativeLongVar();
        $end = $this->makeNativeLongVar();
        $stepOk = $this->makeCompileTimeLongVar(2);
        $stepZero = $this->makeCompileTimeLongVar(0);
        $stepNeg = $this->makeCompileTimeLongVar(-1);
        $stepHuge = $this->makeCompileTimeLongVar(10);
        $ctStart = $this->makeCompileTimeLongVar(0);
        $ctEnd = $this->makeCompileTimeLongVar(8);
        $ctIncStart = $this->makeCompileTimeLongVar(1);
        $ctIncEnd = $this->makeCompileTimeLongVar(5);
        $ctTinyEnd = $this->makeCompileTimeLongVar(1);
        $stepDyn = $this->makeNativeLongVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_combine(),
            [$emptyKeys, $emptyVals]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_combine(),
            [$keysEq, $valsEq]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_combine(),
            [$assocKeys, $assocVals]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new range(),
            [$start, $end]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new range(),
            [$ctStart, $ctEnd, $stepOk]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_combine(),
            [$keysMismatch, $valsMismatch]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_combine(),
            [$ht, $ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_combine(),
            [$keysEmptyPack, $valsEmptyPack]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_combine(),
            [$assocEmpty, $assocEmpty2]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_combine(),
            [$null, $emptyVals]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_combine(),
            [$emptyKeys, $box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_combine(),
            [$emptyKeys]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new range(),
            [$start, $end, $stepDyn]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new range(),
            [$ctStart, $ctEnd, $stepZero]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new range(),
            [$ctIncStart, $ctIncEnd, $stepNeg]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new range(),
            [$ctStart, $ctTinyEnd, $stepHuge]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new range(),
            [$null, $end]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new range(),
            [$start]
        ));
    }

    public function testDiscardedCountCharsAndStrWordCountElideOnSafeArgs(): void
    {
        // php-src ext/standard/string.c count_chars / str_word_count (#36386).
        $context = $this->makeContext();
        $str = $this->makeStringVar('hello world');
        $typedStr = $this->makeStringVar(null);
        $chars = $this->makeStringVar('_');
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $modeOk = $this->makeCompileTimeLongVar(3);
        $modeBad = $this->makeCompileTimeLongVar(5);
        $modeDyn = $this->makeNativeLongVar();
        $fmtOk = $this->makeCompileTimeLongVar(1);
        $fmtBad = $this->makeCompileTimeLongVar(3);
        $fmtDyn = $this->makeNativeLongVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new count_chars(),
            [$str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new count_chars(),
            [$typedStr, $modeOk]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new str_word_count(),
            [$str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new str_word_count(),
            [$typedStr, $fmtOk]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new str_word_count(),
            [$str, $fmtOk, $chars]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new count_chars(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new count_chars(),
            [$str, $modeBad]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new count_chars(),
            [$str, $modeDyn]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new count_chars(),
            [$str, $modeOk, $modeOk]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new str_word_count(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new str_word_count(),
            [$str, $fmtBad]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new str_word_count(),
            [$str, $fmtDyn]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new str_word_count(),
            [$str, $fmtOk, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new str_word_count(),
            [$str, $fmtOk, $box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new str_word_count(),
            [$str, $fmtOk, $chars, $chars]
        ));
    }

    public function testDiscardedStripTagsAndGetHtmlTranslationTableElideOnSafeArgs(): void
    {
        // php-src ext/standard/string.c strip_tags / html.c get_html_translation_table (#36386).
        $context = $this->makeContext();
        $str = $this->makeStringVar('<b>hi</b>');
        $typedStr = $this->makeStringVar(null);
        $allow = $this->makeStringVar('<b>');
        $allowHt = $this->makeHashtableVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $table = $this->makeCompileTimeLongVar(0);
        $flags = $this->makeCompileTimeLongVar(3);
        $tableDyn = $this->makeNativeLongVar();
        $enc = $this->makeStringVar('UTF-8');
        $named = $this->makeNativeLongVar();
        $named->compileTimeConstantName = 'HTML_SPECIALCHARS';

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new strip_tags(),
            [$str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new strip_tags(),
            [$typedStr, $allow]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new strip_tags(),
            [$str, $allowHt]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new strip_tags(),
            [$str, $null]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_html_translation_table(),
            []
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_html_translation_table(),
            [$table]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_html_translation_table(),
            [$tableDyn, $flags]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_html_translation_table(),
            [$named, $flags]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new get_html_translation_table(),
            [$table, $flags, $enc]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strip_tags(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strip_tags(),
            [$str, $box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new strip_tags(),
            [$str, $allow, $allow]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_html_translation_table(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_html_translation_table(),
            [$table, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_html_translation_table(),
            [$table, $flags, $null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new get_html_translation_table(),
            [$table, $flags, $enc, $enc]
        ));
    }

    public function testDiscardedDefinedAndArrayKeyExistsElideOnTypedArgs(): void
    {
        $context = $this->makeContext();
        $str = $this->makeStringVar('PHP_VERSION');
        $lit = $this->makeStringVar('k');
        $ht = $this->makeHashtableVar();
        $long = $this->makeNativeLongVar();
        $dbl = $this->makeNativeDoubleVar();
        $bool = $this->makeNativeBoolVar();
        $null = $this->makeNullVar();
        $box = $this->makeValueBoxVar();
        $obj = $this->makeObjectVar();

        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new defined_(),
            [$str]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new defined_(),
            [$this->makeStringVar('FOO')]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_exists(),
            [$lit, $ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_exists('key_exists'),
            [$long, $ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_exists(),
            [$dbl, $ht]
        ));
        $this->assertTrue(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_exists(),
            [$bool, $ht]
        ));

        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new defined_(),
            []
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new defined_(),
            [$null]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new defined_(),
            [$box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new defined_(),
            [$ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new defined_(),
            [$long]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new defined_(),
            [$str, $lit]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_exists(),
            [$null, $ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_exists(),
            [$obj, $ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_exists(),
            [$box, $ht]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_exists(),
            [$lit, $box]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_exists(),
            [$lit, $obj]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_exists(),
            [$lit]
        ));
        $this->assertFalse(DiscardedPureCallElision::tryElide(
            $context,
            new array_key_exists(),
            [$lit, $ht, $long]
        ));
    }

    public function testJitWiresElisionBeforeInvoke(): void
    {
        $compile = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Concern/CompileBlockInternal.php'
        );
        $this->assertStringContainsString('TYPE_FUNCCALL_EXEC_NORETURN', $compile);
        $this->assertStringContainsString('compileFuncCallExecNoreturnOp', $compile);

        $noreturn = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Concern/CompileFuncCallExecNoreturn.php'
        );
        $this->assertStringContainsString('DiscardedPureCallElision::tryElide', $noreturn);

        // Void-native elision registry lives in the PHP-lowering Concern (not hub JIT.php).
        $lowering = (string) file_get_contents(
            __DIR__.'/../../lib/JIT/Concern/CompileBlockPhpLoweringAndClosurePrep.php'
        );
        $this->assertStringContainsString('discardedCallElisionVoidNatives', $lowering);
        // void(*)(…) formals must still register (#36386 simpleucall hallo(string)).
        $this->assertStringContainsString('$isVoidReturn', $lowering);
        $this->assertStringContainsString('Capture before appending', $lowering);
    }

    private function makeContext(): Context
    {
        $ref = new \ReflectionClass(Context::class);
        /** @var Context $context */
        $context = $ref->newInstanceWithoutConstructor();
        $context->callerStrictTypes = false;

        return $context;
    }

    /**
     * @param list<int> $paramConstraints
     */
    private function makeVoidNative(string $name, array $paramConstraints): Native
    {
        $func = $this->createMock(LlvmFunction::class);
        $native = new Native($func, $name, [], []);
        $constraints = [];
        foreach ($paramConstraints as $idx => $constraint) {
            $constraints[$idx] = $constraint;
        }
        $native->paramTypeConstraintsByArg = $constraints;

        return $native;
    }

    private function makeObjectVar(): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, Variable::TYPE_OBJECT);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, Variable::KIND_VARIABLE);

        return $var;
    }

    private function makeStringVar(?string $literal): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, Variable::TYPE_STRING);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, Variable::KIND_VARIABLE);
        $var->compileTimeString = $literal;

        return $var;
    }

    private function makeNativeLongVar(): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, Variable::TYPE_NATIVE_LONG);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, Variable::KIND_VARIABLE);

        return $var;
    }

    private function makeCompileTimeLongVar(int $value): Variable
    {
        $var = $this->makeNativeLongVar();
        $var->compileTimeLong = $value;

        return $var;
    }

    private function makeNativeDoubleVar(): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, Variable::TYPE_NATIVE_DOUBLE);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, Variable::KIND_VARIABLE);

        return $var;
    }

    private function makeNativeBoolVar(): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, Variable::TYPE_NATIVE_BOOL);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, Variable::KIND_VARIABLE);

        return $var;
    }

    private function makeNullVar(): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, Variable::TYPE_NULL);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, Variable::KIND_VALUE);
        $var->isNullConstant = true;

        return $var;
    }

    private function makeValueBoxVar(): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, Variable::TYPE_VALUE);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, Variable::KIND_VARIABLE);

        return $var;
    }

    private function makeHashtableVar(): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, Variable::TYPE_HASHTABLE);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, Variable::KIND_VARIABLE);

        return $var;
    }

    private function makeNativeLongArrayVar(): Variable
    {
        $ref = new \ReflectionClass(Variable::class);
        /** @var Variable $var */
        $var = $ref->newInstanceWithoutConstructor();
        $typeProp = $ref->getProperty('type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($var, Variable::IS_NATIVE_ARRAY | Variable::TYPE_NATIVE_LONG);
        $kindProp = $ref->getProperty('kind');
        $kindProp->setAccessible(true);
        $kindProp->setValue($var, Variable::KIND_VARIABLE);

        return $var;
    }
}
