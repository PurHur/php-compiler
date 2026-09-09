<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\Config;

/**
 * Helper-runtime NestedJIT-force policy for user-script AOT (#15889 / #36387).
 *
 * Extracted from {@see HelperRuntimeBind} so the inline-only logical list stays a
 * separate TU from bitcode bind / type localize / unit lifecycle (helper-cache
 * granularity / split-TU / size-budget ratchet).
 * Callers keep using HelperRuntimeBind::tryProvide which delegates here.
 *
 * php-src analogy: Zend opcache blacklist / force-recompile lists for scripts that
 * must not attach a shared-memory cache image (Zend/zend_file_cache.c shape) —
 * force NestedJIT of helpers whose prelinked unit.o is known-wrong under thin AOT.
 */
final class HelperRuntimeBindInlineOnly
{
    private const LOGICALS = [
        'phpcompiler\\ext\\standard\\sprintfjithelper::sprintfargv' => true,
        // Same TU as sprintfArgv — linking the prelinked unit.o would reintroduce the
        // NestedJIT `$packed[$i+1]` miscompile (#23871) alongside the inlined fix.
        'phpcompiler\\ext\\standard\\sprintfjithelper::numberformat' => true,
        // #36382 — NestedJIT leaf ParseUrlJitHelper methods into user AOT (not prelinked unit /
        // not componentString→pathOf dispatcher which SEGVs for runtime URL strings).
        'phpcompiler\\ext\\standard\\parseurljithelper::schemeof' => true,
        'phpcompiler\\ext\\standard\\parseurljithelper::hostof' => true,
        'phpcompiler\\ext\\standard\\parseurljithelper::userof' => true,
        'phpcompiler\\ext\\standard\\parseurljithelper::passof' => true,
        'phpcompiler\\ext\\standard\\parseurljithelper::pathof' => true,
        'phpcompiler\\ext\\standard\\parseurljithelper::queryof' => true,
        'phpcompiler\\ext\\standard\\parseurljithelper::fragmentof' => true,
        'phpcompiler\\ext\\standard\\parseurljithelper::portof' => true,
        'phpcompiler\\ext\\standard\\parseurljithelper::hasuser' => true,
        'phpcompiler\\ext\\standard\\parseurljithelper::haspass' => true,
        // #36382 — NestedJIT UriRawurlencodeReplaceJitHelper into user AOT (not prelinked unit);
        // IncludeHelper Nyholm Uri sites must not fall through to PregAotFastPath.
        'phpcompiler\\ext\\standard\\urirawurlencodereplacejithelper::replaceargv' => true,
        // #23912 — force NestedJIT of NestedJIT-safe StrReplaceJitHelper into user AOT
        // (stale/empty helper unit.o otherwise returns "" / wrong bytes for scalar replace).
        'phpcompiler\\ext\\standard\\strreplacejithelper::replaceargv' => true,
        'phpcompiler\\ext\\standard\\strreplacejithelper::ireplaceargv' => true,
        'phpcompiler\\ext\\standard\\strreplacejithelper::takelastcount' => true,
        // #27564 / re-#26827 — helper-runtime PregQuoteJitHelper unit.o returns "" under
        // default cache hit; NestedJIT of the inline-escape helper matches VM/JIT (O=0 OK).
        'phpcompiler\\ext\\standard\\pregquotejithelper::pregquoteargv' => true,
        // #34731 — prelinked FileGetContents/Readfile units lack VmDataUri data:// decode;
        // NestedJIT of the helper (+ VmDataUri bundle) matches Zend/VM.
        'phpcompiler\\ext\\standard\\filegetcontentsjithelper::readpathargv' => true,
        'phpcompiler\\ext\\standard\\readfilejithelper::readfile' => true,
        // #34787 — prelinked MetaTagsJitHelper unit.o skips data:// (libc @file_get_contents);
        // NestedJIT of getMetaTags + FileGetContentsJitHelper::readPathArgv matches Zend/VM.
        'phpcompiler\\ext\\standard\\metatagsjithelper::getmetatags' => true,
        // #25345 — helper-runtime unit.o returns "" for method-return / dynamic string args;
        // NestedJIT recursive escapeFrom works (MiniWebApp $appName).
        'phpcompiler\\ext\\standard\\htmlspecialcharsjithelper::htmlspecialchars' => true,
        'phpcompiler\\ext\\standard\\htmlspecialcharsjithelper::escapefrom' => true,
        // #27050 — helper-runtime HtmlspecialcharsDecodeJitHelper unit.o returns "" under thin
        // AOT (strlen/while accumulator NestedJIT miscompile). Force NestedJIT of recursive
        // decodeFrom (peer #25345 htmlspecialchars encode).
        'phpcompiler\\ext\\standard\\htmlspecialcharsdecodejithelper::htmlspecialcharsdecodeargv' => true,
        'phpcompiler\\ext\\standard\\htmlspecialcharsdecodejithelper::decodefrom' => true,
        // #24156 — prelinked helper TUs lack main-module {closure}_* proxies; NestedJIT
        // closure helpers into the user AOT module so NestedClosureInvoke can dispatch.
        'phpcompiler\\ext\\standard\\arrayreducejithelper::reducewithclosure' => true,
        'phpcompiler\\ext\\standard\\usortjithelper::sortpackedwithclosure' => true,
        'phpcompiler\\ext\\standard\\usortjithelper::sortkeyswithclosure' => true,
        'phpcompiler\\ext\\standard\\usortjithelper::sortvalueswithclosure' => true,
        'phpcompiler\\ext\\standard\\arraymapjithelper::mapwithclosure' => true,
        'phpcompiler\\ext\\standard\\arraymapjithelper::mapwithclosuremultiple' => true,
        'phpcompiler\\ext\\standard\\vmclosureinvoke::invokevariable' => true,
        'phpcompiler\\ext\\standard\\vmclosureinvoke::invokevariabletwo' => true,
        // #26772 — helper-runtime unit.o stubs format → null; NestedJIT self-contained helper.
        'phpcompiler\\ext\\standard\\datetimeformatjithelper::formatstateargv' => true,
        // #36245 — prelinked GC registry/scan unit.o splits PHP statics from main-module
        // phpc_gc_register; route user-script standalone through PHP registry + NativeScan
        // inlined into the user AOT module (peer embed #13882).
        'phpcompiler\\ext\\standard\\gccollectcyclesstandalonejithelper::collectcyclesstandalone' => true,
        'phpcompiler\\ext\\standard\\gccollectcyclesregistryjithelper::appendobject' => true,
        'phpcompiler\\ext\\standard\\gccollectcyclesregistryjithelper::removeobject' => true,
        'phpcompiler\\ext\\standard\\gccollectcyclesregistryjithelper::indexof' => true,
        'phpcompiler\\ext\\standard\\gccollectcyclesregistryjithelper::count' => true,
        'phpcompiler\\ext\\standard\\gccollectcyclesregistryjithelper::objectptr' => true,
        'phpcompiler\\ext\\standard\\gccollectcyclesregistryjithelper::propcount' => true,
        'phpcompiler\\ext\\standard\\gccollectcyclesregistrysyncjithelper::syncfromllvmregistry' => true,
        'phpcompiler\\ext\\standard\\gccollectcyclesnativescanjithelper::collect' => true,
        'phpcompiler\\ext\\standard\\gccollectcyclesnativefreejithelper::freeregistryobject' => true,
        'phpcompiler\\ext\\standard\\gctogglejithelper::isenabled' => true,
        // #27020 — helper-runtime unit.o for JsonEncodeJitHelper embeds eager
        // `$ctx->runtime->vm` / VmJson::export and SIGSEGVs on thin standalone.
        // NestedJIT JsonEncodeNestedJitHelper (Context-free) into the user AOT module.
        'phpcompiler\\ext\\standard\\jsonencodenestedjithelper::encodevalue' => true,
        'phpcompiler\\ext\\standard\\jsonencodenestedjithelper::encodehashtable' => true,
        // #27030 — SerializeJitHelper → VmSerialize SIGSEGVs on thin AOT (arrays/objects).
        // NestedJIT SerializeNestedJitHelper (Context-free) into the user AOT module.
        'phpcompiler\\ext\\standard\\serializenestedjithelper::encodevalue' => true,
        'phpcompiler\\ext\\standard\\serializenestedjithelper::encodehashtable' => true,
        'phpcompiler\\ext\\standard\\serializeobjectnestedjithelper::formatobjectheader' => true,
        'phpcompiler\\ext\\standard\\serializeobjectnestedjithelper::encodeobjectprops' => true,
        // #27030 — NestedJIT O: parse into user AOT (peer serialize object helpers).
        'phpcompiler\\ext\\standard\\unserializeobjectnestedjithelper::isobjectwire' => true,
        'phpcompiler\\ext\\standard\\unserializeobjectnestedjithelper::classname' => true,
        'phpcompiler\\ext\\standard\\unserializeobjectnestedjithelper::propsinto' => true,
        'phpcompiler\\ext\\standard\\unserializeobjectnestedjithelper::firstintprop' => true,
        // #27056 — prelinked StrtrArrayJitHelper unit.o still linked the old
        // VmString::strtrArrayFromHashTable path (list-assign / ExternalMethod stubs)
        // and SIGSEGVd after c:main_before_php. Force NestedJIT of the self-contained
        // helper into the user AOT module.
        'phpcompiler\\ext\\standard\\strtrarrayjithelper::strtrarray' => true,
        // #24137 — prelinked JsonDecodeJitHelper unit.o + heap __string__* bridge UAF;
        // NestedJIT decodeInto into user AOT matches VM (peer #27019 StrWordCount).
        'phpcompiler\\ext\\standard\\jsondecodejithelper::decodeinto' => true,
        'phpcompiler\\ext\\standard\\jsondecodejithelper::decodeint' => true,
        'phpcompiler\\ext\\standard\\jsondecodejithelper::decodebool' => true,
        'phpcompiler\\ext\\standard\\jsondecodejithelper::decodefloat' => true,
        'phpcompiler\\ext\\standard\\jsondecodejithelper::decodestring' => true,
        'phpcompiler\\ext\\standard\\jsondecodejithelper::resulttag' => true,
        // #27019 — helper-runtime StrWordCountJitHelper unit.o returns 0 under thin AOT
        // (default cache hit); NestedJIT of countArgv/wordsArgv matches VM/JIT.
        'phpcompiler\\ext\\standard\\strwordcountjithelper::countargv' => true,
        'phpcompiler\\ext\\standard\\strwordcountjithelper::wordsargv' => true,
        // #27436 / re-#27345 — helper-runtime StrIncdecJitHelper unit.o can return "" under
        // default cache hit (fingerprint-fresh but IR-stale vs NestedJIT into user AOT);
        // NestedJIT of NestedJIT-safe incrementArgv/decrementArgv matches VM/JIT (O=0 OK).
        'phpcompiler\\ext\\standard\\strincdecjithelper::incrementargv' => true,
        'phpcompiler\\ext\\standard\\strincdecjithelper::decrementargv' => true,
        // #27069 — NestedJIT CsvStrGetcsvJitHelper (no VmFs) into user AOT; prelinked
        // CsvJitHelper TU + whole-file NestedJIT of fgetcsvArgv/VmFs SIGSEGVd.
        'phpcompiler\\ext\\standard\\csvstrgetcsvjithelper::strgetcsvargv' => true,
        'phpcompiler\\ext\\standard\\csvstrgetcsvjithelper::striplineterminatorsargv' => true,
        // #27180 — NestedJIT CsvFputcsvJitHelper::formatFieldArgv (no HashTable::iterate /
        // VmFputcsv); LLVM walks fields (peer JitImplode). Prelinked CsvJitHelper
        // formatFieldsArgv SIGSEGVs under thin AOT.
        'phpcompiler\\ext\\standard\\csvfputcsvjithelper::formatfieldargv' => true,
        // #27068 — NestedJIT FilterEmailValidate into user AOT (avoid stale
        // FilterEmailJitHelper ?string unit.o). Const emails fold in JitFilter.
        'phpcompiler\\ext\\filter\\filteremailvalidate::isvalidint' => true,
        'phpcompiler\\ext\\filter\\filteremailvalidate::isvalid' => true,
        // #26989 — PendingHeadersJitHelper unit.o calls __compiler_preg_match without a provider
        // in the helper TU; NestedJIT into the user module so PregMatchRuntime can link.
        'phpcompiler\\ext\\standard\\pendingheadersjithelper::reset' => true,
        'phpcompiler\\ext\\standard\\pendingheadersjithelper::enableheaderqueue' => true,
        'phpcompiler\\ext\\standard\\pendingheadersjithelper::isflushed' => true,
        'phpcompiler\\ext\\standard\\pendingheadersjithelper::addheader' => true,
        'phpcompiler\\ext\\standard\\pendingheadersjithelper::removeheader' => true,
        'phpcompiler\\ext\\standard\\pendingheadersjithelper::listheaderstable' => true,
        'phpcompiler\\ext\\standard\\pendingheadersjithelper::snapshotheaderstable' => true,
        'phpcompiler\\ext\\standard\\pendingheadersjithelper::flushresponseheaders' => true,
        'phpcompiler\\ext\\standard\\pendingheadersjithelper::addsetcookie' => true,
        // #30790 — prelinked Soundex/Levenshtein unit.o SIGSEGVs under thin AOT; NestedJIT
        // VmSoundex / VmLevenshtein (recursive substr / CSV rows) into the user module.
        'phpcompiler\\ext\\standard\\soundexjithelper::soundexargv' => true,
        'phpcompiler\\ext\\standard\\levenshteinjithelper::computeargv' => true,
        // #30811 — prelinked ConvertUuJitHelper unit.o SIGSEGVs under thin AOT; NestedJIT
        // VmConvertUu (strlen/substr/ord/chr) into the user module (peer #30790).
        'phpcompiler\\ext\\standard\\convertuujithelper::encode' => true,
        'phpcompiler\\ext\\standard\\convertuujithelper::decodeargv' => true,
        // #32879 — prelinked Utf8Latin1JitHelper unit.o SIGSEGVs under thin AOT when the
        // encode/decode return is used (echo/strlen); void discard OK. NestedJIT into the
        // user module (peer #30811 ConvertUu / #30790 Soundex).
        'phpcompiler\\ext\\standard\\utf8latin1jithelper::encode' => true,
        'phpcompiler\\ext\\standard\\utf8latin1jithelper::decodeargv' => true,
        // #30812 — prelinked WordwrapJitHelper unit.o SIGSEGVs under thin AOT; NestedJIT
        // VmWordwrap (strlen/substr) into the user module (peer #30790 / #30811).
        'phpcompiler\\ext\\standard\\wordwrapjithelper::wordwrapargv' => true,
        // #3258 — prelinked GethostbynamelJitHelper unit.o leaves resolveHostnameIpv4List null
        // under thin AOT (count() TypeError on gethostbyname/gethostbynamel); NestedJIT VmDns bundle.
        'phpcompiler\\ext\\standard\\gethostbynameljithelper::ipcount' => true,
        'phpcompiler\\ext\\standard\\gethostbynameljithelper::ipat' => true,
        // #30859 / re-#26992 — prelinked ChunkSplitJitHelper unit.o SIGSEGVs under thin AOT;
        // NestedJIT VmChunkSplit (strlen/substr) into the user module (peer #30811).
        'phpcompiler\\ext\\standard\\chunksplitjithelper::chunksplitargv' => true,
        // #30858 / re-#27011 — prelinked QuotemetaJitHelper unit.o SIGSEGVs under thin AOT;
        // NestedJIT VmQuotemeta (strlen/substr) into the user module (peer #30859).
        'phpcompiler\\ext\\standard\\quotemetajithelper::quotemetaargv' => true,
        // #33956 / #33950 — default-TZ helper + date() T/e/O/P tokens share NestedJIT statics;
        // prelinked civil unit still reads VmDate and UTC-bakes free date('T') after set.
        'phpcompiler\\ext\\standard\\defaulttimezonejithelper::defaulttimezoneget' => true,
        'phpcompiler\\ext\\standard\\defaulttimezonejithelper::trydefaulttimezoneset' => true,
        'phpcompiler\\ext\\standard\\defaulttimezonejithelper::emitinvalidtimezonenotice' => true,
        'phpcompiler\\ext\\standard\\defaulttimezoneciviljithelper::localciviltimestamp' => true,
        'phpcompiler\\ext\\standard\\defaulttimezoneciviljithelper::localisdst' => true,
        'phpcompiler\\ext\\standard\\defaulttimezoneciviljithelper::formattimezonetoken' => true,
        'phpcompiler\\ext\\standard\\defaulttimezoneciviljithelper::formattokent' => true,
        'phpcompiler\\ext\\standard\\defaulttimezoneciviljithelper::formattokene' => true,
        'phpcompiler\\ext\\standard\\defaulttimezoneciviljithelper::formattokeno' => true,
        'phpcompiler\\ext\\standard\\defaulttimezoneciviljithelper::formattokenp' => true,
        // #33059 — prelinked IniJitHelper unit.o returns "0" for every ini_get under thin
        // AOT (NestedJIT PHP static defaults BSS-zero; ?string ABI collapses). Force NestedJIT
        // into the user module (peer #30858 Quotemeta / getenv string|false).
        'phpcompiler\\ext\\standard\\inijithelper::iniget' => true,
        'phpcompiler\\ext\\standard\\inijithelper::iniset' => true,
        'phpcompiler\\ext\\standard\\inijithelper::inicfgget' => true,
        'phpcompiler\\ext\\standard\\inijithelper::inirestore' => true,
        'phpcompiler\\ext\\standard\\inijithelper::getprecisionint' => true,
        'phpcompiler\\ext\\standard\\inijithelper::getserializeprecisionint' => true,
        'phpcompiler\\ext\\standard\\inijithelper::ensurecompiledmoduledefaults' => true,
        'phpcompiler\\ext\\standard\\inigetleafjithelper::iniget' => true,
        'phpcompiler\\ext\\standard\\inigetleafjithelper::iniset' => true,
        'phpcompiler\\ext\\standard\\inigetleafjithelper::inirestore' => true,
        'phpcompiler\\ext\\standard\\inigetleafjithelper::getprecisionint' => true,
        'phpcompiler\\ext\\standard\\inigetleafjithelper::getserializeprecisionint' => true,
        // #30813 — prelinked Nl2brJitHelper unit.o SIGSEGVs under thin AOT; NestedJIT
        // VmNl2br (strlen/substr) into the user module (peer #30812 / #30859).
        'phpcompiler\\ext\\standard\\nl2brjithelper::nl2brargv' => true,
        // #31099 — NestedJIT UrlRewriterApply during emitAdd only (not Context init);
        // prelinked unit.o not used for user-script rewrite apply.
        'phpcompiler\\ext\\standard\\urlrewriterapplyjithelper::applyargv' => true,
        // Differential AOT — prelinked StrRepeatJitHelper unit.o SIGSEGVs/OOM-kills under
        // thin AOT (e10_method, str_repeat); NestedJIT StrRepeatJitHelper into user module
        // (peer #30812 / #30859 / re-#27007).
        'phpcompiler\\ext\\standard\\strrepeatjithelper::strrepeatargv' => true,
        // Differential AOT — prelinked StrPadJitHelper unit.o SIGSEGVs (c11_strcmp, e15_str_fns);
        // NestedJIT StrPadJitHelper into user module (peer #30812).
        'phpcompiler\\ext\\standard\\strpadjithelper::padargv' => true,
        // re-#27007 — prelinked StrrevJitHelper unit.o SIGSEGVs under thin AOT; NestedJIT
        // StrrevJitHelper into user module (peer #30812).
        'phpcompiler\\ext\\standard\\strrevjithelper::strrevargv' => true,
        // re-#26890 — prelinked Base64JitHelper::decodeArgv unit.o SIGSEGVs under thin AOT
        // (encodeArgv prelinked path stays green); NestedJIT decodeArgv into user module.
        'phpcompiler\\ext\\standard\\base64jithelper::decodeargv' => true,
        // #34800 — prelinked encodeArgv mis-reads `$data[$i+1]` / `<<` on binary; NestedJIT
        // `$i++` + intdiv encode matches Zend (peer MbMimeheaderJitHelper::b64Encode).
        'phpcompiler\\ext\\standard\\base64jithelper::encodeargv' => true,
        // #35378 — prelinked SodiumBase64JitHelper unit.o throws base Exception (not
        // SodiumException) on invalid variant id, so catch (SodiumException) misses it;
        // NestedJIT Base64JitHelper + SodiumBase64JitHelper bundle matches VM/JIT.
        'phpcompiler\\ext\\sodium\\sodiumbase64jithelper::bin2base64argv' => true,
        // #34824 — prelinked Crc32JitHelper unit.o miscomputes digests (equal-length strings
        // share one wrong CRC). NestedJIT of the bit-by-bit helper into the user AOT module
        // matches Zend/VM (same algorithm as user-script AOT; peer #34800 / #27077).
        'phpcompiler\\ext\\standard\\crc32jithelper::crc32argv' => true,
        'phpcompiler\\ext\\standard\\crc32jithelper::crc32cargv' => true,
        // #34828 — prelinked HashCryptoJitHelper unit.o only has EVP; NestedJIT of hash() +
        // HashNonCryptoJitHelper into the user AOT module matches Zend for crc32/adler32/fnv*.
        // Inline the whole HashCrypto TU so hmac/pbkdf2/hkdf from the same unit.o cannot win.
        'phpcompiler\\ext\\standard\\hashcryptojithelper::hash' => true,
        'phpcompiler\\ext\\standard\\hashcryptojithelper::hashhmac' => true,
        'phpcompiler\\ext\\standard\\hashcryptojithelper::hashpbkdf2' => true,
        'phpcompiler\\ext\\standard\\hashcryptojithelper::hashhkdf' => true,
        'phpcompiler\\ext\\standard\\hashnoncryptojithelper::supports' => true,
        'phpcompiler\\ext\\standard\\hashnoncryptojithelper::digest' => true,
        // re-#26868 — prelinked StrRot13JitHelper unit.o SIGSEGVs under thin AOT; NestedJIT
        // rot13Argv into user module (peer #26890 / #30812).
        'phpcompiler\\ext\\standard\\strrot13jithelper::rot13argv' => true,
        // re-#26899 — prelinked QuotPrintJitHelper unit.o SIGSEGVs under thin AOT; NestedJIT
        // encode/decode into user module (peer #26868).
        'phpcompiler\\ext\\standard\\quotprintjithelper::encode' => true,
        'phpcompiler\\ext\\standard\\quotprintjithelper::decode' => true,
        // #32962 — prelinked DomC14NJitHelper unit.o returns Variable as __object__* under
        // thin AOT (echo "Object"); NestedJIT ?string helper + string→value bridge.
        'phpcompiler\\ext\\dom\\domc14njithelper::c14nargv' => true,
        // #32741 — prelinked StatPathJitHelper unit.o embeds VmOpenBasedir::check which reads
        // as always-active under thin AOT (is_file/file_exists/is_dir always false); NestedJIT
        // current source (stat kernel only, peer isReadable) into the user module.
        'phpcompiler\\ext\\standard\\statpathjithelper::exists' => true,
        'phpcompiler\\ext\\standard\\statpathjithelper::isfile' => true,
        'phpcompiler\\ext\\standard\\statpathjithelper::isdir' => true,
        'phpcompiler\\ext\\standard\\statpathjithelper::islink' => true,
        // #34278 — prelinked MbStrSplitJitHelper unit.o returns HashTable and SIGSEGVs under
        // thin AOT (NestedJIT cannot construct HashTable — peer explode #27660). Force NestedJIT
        // of the string-joined peel into the user module; JitMbStrSplit rebuilds HT via JitExplode.
        'phpcompiler\\ext\\mbstring\\mbstrsplitjithelper::strsplitargv' => true,
        // #35315 — prelinked MbConvertVariablesJitHelper unit.o aborts at runtime (MbDetectEncoding
        // dep chain lacks init under HELPER_RUNTIME_O=1). NestedJIT bundle into user AOT matches O=0.
        'phpcompiler\\ext\\mbstring\\mbconvertvariablesjithelper::convertstringargv' => true,
        'phpcompiler\\ext\\mbstring\\mbconvertvariablesjithelper::detectfromargv' => true,
    ];

    /**
     * True when user-script AOT must NestedJIT this logical instead of binding prelinked unit.o.
     */
    public static function shouldInlineOnlyForUserScript(string $logicalLc): bool
    {
        if (!isset(self::LOGICALS[$logicalLc])) {
            return false;
        }
        $user = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');

        return '1' === $user || 'true' === strtolower((string) $user);
    }

}
