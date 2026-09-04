<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Block;
use PHPCompiler\ext\standard\abs;
use PHPCompiler\ext\standard\addcslashes;
use PHPCompiler\ext\standard\addslashes;
use PHPCompiler\ext\standard\array_key_exists;
use PHPCompiler\ext\standard\array_count;
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
use PHPCompiler\ext\standard\convert_uuencode;
use PHPCompiler\ext\standard\crc32;
use PHPCompiler\ext\standard\decbin;
use PHPCompiler\ext\standard\dechex;
use PHPCompiler\ext\standard\decoct;
use PHPCompiler\ext\standard\defined_;
use PHPCompiler\ext\standard\dirname;
use PHPCompiler\ext\standard\escapeshellarg;
use PHPCompiler\ext\standard\escapeshellcmd;
use PHPCompiler\ext\standard\explode;
use PHPCompiler\ext\standard\extension_loaded;
use PHPCompiler\ext\standard\enum_exists_;
use PHPCompiler\ext\standard\fdiv;
use PHPCompiler\ext\standard\fmax;
use PHPCompiler\ext\standard\fmin;
use PHPCompiler\ext\standard\floatval;
use PHPCompiler\ext\standard\function_exists;
use PHPCompiler\ext\standard\get_class_;
use PHPCompiler\ext\standard\get_debug_type;
use PHPCompiler\ext\standard\get_parent_class_;
use PHPCompiler\ext\standard\gettype;
use PHPCompiler\ext\standard\hash_equals;
use PHPCompiler\ext\standard\hebrev;
use PHPCompiler\ext\standard\hexdec;
use PHPCompiler\ext\standard\html_entity_decode;
use PHPCompiler\ext\standard\htmlentities;
use PHPCompiler\ext\standard\htmlspecialchars;
use PHPCompiler\ext\standard\htmlspecialchars_decode;
use PHPCompiler\ext\standard\int_max;
use PHPCompiler\ext\standard\int_min;
use PHPCompiler\ext\standard\intval;
use PHPCompiler\ext\standard\inet_ntop;
use PHPCompiler\ext\standard\inet_pton;
use PHPCompiler\ext\standard\interface_exists_;
use PHPCompiler\ext\standard\ip2long;
use PHPCompiler\ext\standard\is_a_;
use PHPCompiler\ext\standard\is_subclass_of_;
use PHPCompiler\ext\standard\levenshtein;
use PHPCompiler\ext\standard\long2ip;
use PHPCompiler\ext\standard\method_exists_;
use PHPCompiler\ext\standard\md5;
use PHPCompiler\ext\standard\metaphone;
use PHPCompiler\ext\standard\nl2br;
use PHPCompiler\ext\standard\number_format;
use PHPCompiler\ext\standard\octdec;
use PHPCompiler\ext\standard\ord;
use PHPCompiler\ext\standard\parse_url;
use PHPCompiler\ext\standard\pathinfo;
use PHPCompiler\ext\standard\property_exists_;
use PHPCompiler\ext\standard\pi;
use PHPCompiler\ext\standard\pow;
use PHPCompiler\ext\standard\preg_quote;
use PHPCompiler\ext\standard\quoted_printable_decode;
use PHPCompiler\ext\standard\quoted_printable_encode;
use PHPCompiler\ext\standard\quotemeta;
use PHPCompiler\ext\standard\rawurldecode;
use PHPCompiler\ext\standard\rawurlencode;
use PHPCompiler\ext\standard\sha1;
use PHPCompiler\ext\standard\similar_text;
use PHPCompiler\ext\standard\soundex;
use PHPCompiler\ext\standard\spl_object_hash;
use PHPCompiler\ext\standard\spl_object_id;
use PHPCompiler\ext\standard\sqrt;
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
use PHPCompiler\ext\standard\strcasecmp;
use PHPCompiler\ext\standard\strcmp;
use PHPCompiler\ext\standard\string_trim;
use PHPCompiler\ext\standard\stripcslashes;
use PHPCompiler\ext\standard\strpbrk;
use PHPCompiler\ext\standard\strpos;
use PHPCompiler\ext\standard\strtolower;
use PHPCompiler\ext\standard\strtr;
use PHPCompiler\ext\standard\strval;
use PHPCompiler\ext\standard\substr;
use PHPCompiler\ext\standard\substr_replace;
use PHPCompiler\ext\standard\trait_exists_;
use PHPCompiler\ext\standard\ucwords;
use PHPCompiler\ext\standard\urldecode;
use PHPCompiler\ext\standard\urlencode;
use PHPCompiler\ext\standard\version_compare;
use PHPCompiler\ext\standard\wordwrap;
use PHPCompiler\ext\standard\boolval;
use PHPCompiler\ext\types\is_type;
use PHPCompiler\ext\types\strlen;
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
        $this->assertStringContainsString('DiscardedPureCallElision::tryElide', $compile);
        $this->assertStringContainsString('TYPE_FUNCCALL_EXEC_NORETURN', $compile);

        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString('discardedCallElisionVoidNatives', $jit);
        // void(*)(…) formals must still register (#36386 simpleucall hallo(string)).
        $this->assertStringContainsString('$isVoidReturn', $jit);
        $this->assertStringContainsString('Capture before appending', $jit);
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
}
