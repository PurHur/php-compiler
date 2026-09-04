<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Block;
use PHPCompiler\ext\standard\abs;
use PHPCompiler\ext\standard\addslashes;
use PHPCompiler\ext\standard\array_count;
use PHPCompiler\ext\standard\base64_encode;
use PHPCompiler\ext\standard\bin2hex;
use PHPCompiler\ext\standard\chr;
use PHPCompiler\ext\standard\convert_uuencode;
use PHPCompiler\ext\standard\crc32;
use PHPCompiler\ext\standard\escapeshellarg;
use PHPCompiler\ext\standard\escapeshellcmd;
use PHPCompiler\ext\standard\fdiv;
use PHPCompiler\ext\standard\gettype;
use PHPCompiler\ext\standard\hebrev;
use PHPCompiler\ext\standard\html_entity_decode;
use PHPCompiler\ext\standard\htmlentities;
use PHPCompiler\ext\standard\htmlspecialchars;
use PHPCompiler\ext\standard\htmlspecialchars_decode;
use PHPCompiler\ext\standard\md5;
use PHPCompiler\ext\standard\metaphone;
use PHPCompiler\ext\standard\nl2br;
use PHPCompiler\ext\standard\ord;
use PHPCompiler\ext\standard\pow;
use PHPCompiler\ext\standard\preg_quote;
use PHPCompiler\ext\standard\quotemeta;
use PHPCompiler\ext\standard\rawurldecode;
use PHPCompiler\ext\standard\rawurlencode;
use PHPCompiler\ext\standard\sha1;
use PHPCompiler\ext\standard\soundex;
use PHPCompiler\ext\standard\sqrt;
use PHPCompiler\ext\standard\str_repeat;
use PHPCompiler\ext\standard\str_rot13;
use PHPCompiler\ext\standard\strcasecmp;
use PHPCompiler\ext\standard\strcmp;
use PHPCompiler\ext\standard\string_trim;
use PHPCompiler\ext\standard\strpos;
use PHPCompiler\ext\standard\strtolower;
use PHPCompiler\ext\standard\substr;
use PHPCompiler\ext\standard\ucwords;
use PHPCompiler\ext\standard\urldecode;
use PHPCompiler\ext\standard\urlencode;
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

    public function testDoesNotElideMd5WithBoolBinaryArg(): void
    {
        // Optional $binary is not a string slot — keep the call (#36386).
        $context = $this->makeContext();
        $builtin = new md5();
        $str = $this->makeStringVar('ab');
        $raw = $this->makeNativeBoolVar();

        $this->assertFalse(DiscardedPureCallElision::tryElide($context, $builtin, [$str, $raw]));
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
