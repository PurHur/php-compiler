<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\Context;
use PHPCompiler\Config;

require_once __DIR__.'/HelperRuntimeFingerprint.php';

/**
 * Incremental split-compilation cache for php-in-PHP JIT helpers (#15889).
 *
 * Each helper unit is its own translation unit, cached independently:
 *
 *   build/helper-runtime-cache/units/<slug>/
 *     unit.bc        — bitcode; per-script builds read exact function types
 *     unit.o         — object the Linker merges at the end
 *     manifest.json  — {fingerprint, unit, deps?, helpers: logical → symbol}
 *     failed.json    — {fingerprint, rc} when the unit's lowering crashes;
 *                      re-attempted only when its fingerprint changes
 *
 * Freshness is PER UNIT (#23458):
 *
 *   v2 (manifest has deps[]): sha256(global + unit source + each dep's content)
 *   v1 (legacy, no deps):     sha256(legacy lowering core + unit source)
 *
 * Global inputs ({@see coreFingerprint}) are composer.lock, patches, LLVM
 * library identity (#24381), and runtime struct layout sources
 * ({@see runtimeLayoutFingerprintPaths}) — content hash of libLLVM-9.so.1,
 * not the install path string, so host `.llvm` and Docker `/opt/llvm9` with
 * the same bytes share a fingerprint. Editing lib/JIT.php no longer
 * invalidates the whole corpus; editing a layout `.pre` (e.g. __value__ ABI
 * #36214) does. Emit records the NestedJIT closure in deps[]; editing one
 * reached lowering invalidates only units that listed it. Legacy manifests
 * keep the old JIT-core key until re-emitted so the committed prelinked tier
 * stays usable.
 *
 * Opt-in: PHP_COMPILER_HELPER_RUNTIME_O=1.
 *
 * Fingerprint / identity / unit-deps hashing lives in {@see HelperRuntimeFingerprint}
 * (#36387 / #36403 size-budget ratchet).
 */
final class HelperRuntimeCache
{
    private const ENV_FLAG = 'PHP_COMPILER_HELPER_RUNTIME_O';

    private const ENV_DIR = 'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR';

    /** Guard so the emitter itself never consumes the cache. */
    private const ENV_EMITTING = 'PHP_COMPILER_HELPER_RUNTIME_EMITTING';

    /** Marker for a warmed cache at a given core fingerprint (#15889). */
    private const CORE_MARKER_PREFIX = 'core-';

    /** @var array<string, array{symbol: string, dir: string}>|null logical(lower) → binding */
    private static ?array $helperIndex = null;

    /** @var array<string, object> unit dir → parsed bitcode module (kept alive: types are shared) */
    private static array $parsedUnits = [];

    /** @var array<string, object> bitcode path → parsed module (chunk manifest bind, #36155) */
    private static array $parsedBitcodeFiles = [];

    /** @var array<string, true> unit dir → merged at link time */
    private static array $usedUnits = [];

    /**
     * User-script AOT previously forced inline compile for stale prelink units (#17954).
     * ObjectEntry ABI + ext/dom fingerprint deps invalidate stale helper TUs.
     *
     * @var array<string, true>
     */
    private const USER_SCRIPT_INLINE_ONLY_LOGICALS = [
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

    private static bool $loggedHit = false;

    public static function enabled(): bool
    {
        if ('1' === getenv(self::ENV_EMITTING)) {
            return false;
        }
        $flag = getenv(self::ENV_FLAG);

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    public static function cacheDir(): string
    {
        $dir = getenv(self::ENV_DIR);
        if (is_string($dir) && '' !== $dir) {
            return rtrim($dir, '/');
        }

        return \dirname(__DIR__, 2).'/build/helper-runtime-cache';
    }

    private static function coreMarkerPath(): string
    {
        return self::cacheDir().'/'.self::CORE_MARKER_PREFIX.self::coreFingerprint().'.ok';
    }

    /**
     * Best-effort warmup for user-script AOT builds (#15889).
     *
     * When the cache is enabled but cold, run the incremental helper-unit emitter once per core
     * fingerprint. Subsequent builds should be cache hits with no nested helper lowering.
     */
    public static function warmForUserAotBuild(): void
    {
        if (!self::enabled()) {
            return;
        }
        // Only for user-script AOT builds; bootstrap/self-host pipelines own their own emit ladders.
        $user = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' !== $user && 'true' !== strtolower((string) $user)) {
            return;
        }
        $marker = self::coreMarkerPath();
        if (is_file($marker)) {
            return;
        }
        // The marker lives under build/helper-runtime-cache, which is gitignored — so a CLEAN
        // CHECKOUT never has it and every first user AOT build re-emitted the whole corpus, even
        // when the committed per-arch cache was present and current. Measured: ~517s to compile
        // `<?php echo "hi\n";` on a fresh tree, 5s once warm (#24302).
        //
        // helperIndex() already skips stale units per fingerprint and NestedJIT fills gaps, so a
        // patches/ or composer.lock change that drifts core_fingerprint must NOT launch a 410-unit
        // emit from `phpc build` / aot-smoke (120s timeout, rc=124). Presence of committed unit.o
        // files is enough to skip the corpus warmup (#32122). Maintainers refresh with
        // emit-helper-runtime-object.php --prelink or --refresh-global-fingerprints.
        if (self::committedCacheHasUnits()) {
            @mkdir(\dirname($marker), 0755, true);
            @file_put_contents($marker, 'ok (committed units present; skip corpus warmup) '.gmdate('c')."\n");

            return;
        }

        $root = \dirname(__DIR__, 2);
        $script = $root.'/script/emit-helper-runtime-object.php';
        if (!is_file($script)) {
            return;
        }
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script);
        $rc = self::runWarmupCommand($cmd);
        if (0 === $rc) {
            @mkdir(\dirname($marker), 0755, true);
            @file_put_contents($marker, 'ok '.gmdate('c')."\n");
            // Any new units should be visible immediately.
            self::$helperIndex = null;
        }
    }

    /**
     * Committed per-arch cache has objects we can skip whole-corpus warmup for (#24302 / #32122).
     *
     * Core-fingerprint drift is not a reason to emit 410 units from a user-script compile.
     * helperIndex() still skips stale units per fingerprint; NestedJIT fills gaps. Only a missing
     * or empty committed tree (wrong arch / incomplete clone) falls through to warmup.
     */
    private static function committedCacheHasUnits(): bool
    {
        $unitsDir = self::prelinkedUnitsDir();
        if (!is_dir($unitsDir)) {
            return false;
        }
        $entries = @scandir($unitsDir);
        if (false === $entries) {
            return false;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $dir = $unitsDir.'/'.$entry;
            if (is_dir($dir) && is_file($dir.'/unit.o') && is_file($dir.'/unit.bc')) {
                return true;
            }
        }

        return false;
    }

    private static function runWarmupCommand(string $command): int
    {
        // Prefer the in-repo polyfill when available (self-host safe).
        if (\function_exists('phpc_run_command')) {
            $out = \phpc_run_command($command);
            if (\is_array($out)) {
                return (int) ($out['code'] ?? 127);
            }
        }
        $ignored = [];
        $rc = 127;
        @exec($command.' 2>/dev/null', $ignored, $rc);

        return (int) $rc;
    }

    public static function unitsDir(): string
    {
        return self::cacheDir().'/units';
    }

    public static function unitDir(string $slug): string
    {
        return self::unitsDir().'/'.$slug;
    }

    public static function slugFor(string $unitPath): string
    {
        return (string) preg_replace('#[^A-Za-z0-9]+#', '_', trim($unitPath, '/'));
    }

    /** Architecture key for shareable prelinked unit objects, e.g. "x86_64-linux" (#36391). */
    public static function archKey(): string
    {
        return CompileTarget::current()->id();
    }

    /** Committed per-arch unit cache: prelinked/helper-runtime/<arch>/units. */
    public static function prelinkedUnitsDir(): string
    {
        return CompileTarget::current()->helperRuntimeArchDir(\dirname(__DIR__, 2)).'/units';
    }

    /**
     * Global inputs (#23458 / #24381): composer.lock, patches, LLVM library,
     * runtime struct layout `.pre` sources (#36214).
     *
     * @see HelperRuntimeFingerprint::coreFingerprint()
     */
    public static function coreFingerprint(): string
    {
        return HelperRuntimeFingerprint::coreFingerprint();
    }

    /**
     * Digest of linkable helper-runtime units for MCJIT/AOT compile-cache keys (#36199).
     *
     * @see HelperRuntimeFingerprint::cacheKeySegment()
     */
    public static function cacheKeySegment(): string
    {
        return HelperRuntimeFingerprint::cacheKeySegment();
    }

    /** @see HelperRuntimeFingerprint::legacyLoweringFingerprint() */
    public static function legacyLoweringFingerprint(): string
    {
        return HelperRuntimeFingerprint::legacyLoweringFingerprint();
    }

    /** @see HelperRuntimeFingerprint::llvmIdentityToken() */
    public static function llvmIdentityToken(): string
    {
        return HelperRuntimeFingerprint::llvmIdentityToken();
    }

    /**
     * @return list<string>
     *
     * @see HelperRuntimeFingerprint::equivalentCoreFingerprints()
     */
    public static function equivalentCoreFingerprints(): array
    {
        return HelperRuntimeFingerprint::equivalentCoreFingerprints();
    }

    /** @see HelperRuntimeFingerprint::coreFingerprintMatches() */
    public static function coreFingerprintMatches(string $candidate): bool
    {
        return HelperRuntimeFingerprint::coreFingerprintMatches($candidate);
    }

    /**
     * @return list<string> repo-root-relative paths
     *
     * @see HelperRuntimeFingerprint::runtimeLayoutFingerprintPaths()
     */
    public static function runtimeLayoutFingerprintPaths(): array
    {
        return HelperRuntimeFingerprint::runtimeLayoutFingerprintPaths();
    }

    /**
     * Repo-root relative path (/lib/… or /ext/…) for an absolute file, or null.
     *
     * @see HelperRuntimeFingerprint::repoRelPath()
     */
    public static function repoRelPath(string $absPath): ?string
    {
        return HelperRuntimeFingerprint::repoRelPath($absPath);
    }

    /**
     * @param list<string> $compiledAbsPaths from Context::listJitCompiledIncludePaths()
     *
     * @return list<string> sorted unique repo-relative paths
     *
     * @see HelperRuntimeFingerprint::dependencyRelPathsForEmit()
     */
    public static function dependencyRelPathsForEmit(string $unitSourceAbsPath, array $compiledAbsPaths): array
    {
        return HelperRuntimeFingerprint::dependencyRelPathsForEmit($unitSourceAbsPath, $compiledAbsPaths);
    }

    /**
     * @param list<string>|null $depsRelPaths repo-relative paths; null = v2 with unit-only + extras
     *
     * @see HelperRuntimeFingerprint::unitFingerprint()
     */
    public static function unitFingerprint(string $unitSourceAbsPath, ?array $depsRelPaths = null): string
    {
        return HelperRuntimeFingerprint::unitFingerprint($unitSourceAbsPath, $depsRelPaths);
    }

    /**
     * @param array{fingerprint?: string, unit?: string, deps?: list<string>|mixed} $manifest
     *
     * @see HelperRuntimeFingerprint::expectedFingerprintForManifest()
     */
    public static function expectedFingerprintForManifest(array $manifest, string $unitSourceAbsPath): string
    {
        return HelperRuntimeFingerprint::expectedFingerprintForManifest($manifest, $unitSourceAbsPath);
    }

    /** @see HelperRuntimeFingerprint::manifestFingerprintMatches() */
    public static function manifestFingerprintMatches(array $manifest, string $unitSourceAbsPath): bool
    {
        return HelperRuntimeFingerprint::manifestFingerprintMatches($manifest, $unitSourceAbsPath);
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>|null
     *
     * @see HelperRuntimeFingerprint::migrateManifestToV2()
     */
    public static function migrateManifestToV2(array $manifest, string $unitSourceAbsPath): ?array
    {
        return HelperRuntimeFingerprint::migrateManifestToV2($manifest, $unitSourceAbsPath);
    }

    /**
     * @param list<string> $depsRelPaths
     *
     * @see HelperRuntimeFingerprint::fingerprintV2()
     */
    public static function fingerprintV2(string $unitSourceAbsPath, array $depsRelPaths): string
    {
        return HelperRuntimeFingerprint::fingerprintV2($unitSourceAbsPath, $depsRelPaths);
    }

    /**
     * @param list<string> $depsRelPaths
     *
     * @see HelperRuntimeFingerprint::fingerprintV2WithCore()
     */
    public static function fingerprintV2WithCore(string $unitSourceAbsPath, array $depsRelPaths, string $core): string
    {
        return HelperRuntimeFingerprint::fingerprintV2WithCore($unitSourceAbsPath, $depsRelPaths, $core);
    }


    /** @return array{fingerprint: string, unit: string, helpers: array<string,string>}|null */
    public static function unitManifest(string $slug, ?string $unitDir = null): ?array
    {
        $path = ($unitDir ?? self::unitDir($slug)).'/manifest.json';
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!\is_array($decoded) || !isset($decoded['fingerprint'], $decoded['helpers']) || !\is_array($decoded['helpers'])) {
            return null;
        }

        return $decoded;
    }

    /** @return array{fingerprint: string, rc: int}|null persisted crash marker */
    public static function unitFailure(string $slug): ?array
    {
        $path = self::unitDir($slug).'/failed.json';
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return \is_array($decoded) && isset($decoded['fingerprint']) ? $decoded : null;
    }

    /**
     * logical(lower) → {symbol, dir} across all FRESH unit manifests.
     * Built lazily once per process; adding a unit invalidates nothing else.
     *
     * The local build cache is scanned first and wins; the committed per-arch
     * prelinked cache (a fresh clone's warm start) fills the gaps. Stale
     * entries in either tier are skipped per unit — a stale committed cache
     * can only make a build slower, never wrong.
     *
     * @return array<string, array{symbol: string, dir: string}>
     */
    private static function helperIndex(): array
    {
        if (null !== self::$helperIndex) {
            return self::$helperIndex;
        }
        $index = [];
        $root = \dirname(__DIR__, 2);
        foreach ([self::unitsDir(), self::prelinkedUnitsDir()] as $unitsRoot) {
            foreach (glob($unitsRoot.'/*/manifest.json') ?: [] as $manifestPath) {
                $unitDir = \dirname($manifestPath);
                $slug = basename($unitDir);
                $manifest = self::unitManifest($slug, $unitDir);
                if (null === $manifest) {
                    continue;
                }
                $sourceAbs = self::resolveUnitSource($root, (string) $manifest['unit']);
                if (null === $sourceAbs || !self::manifestFingerprintMatches($manifest, $sourceAbs)) {
                    continue; // stale — emitter will refresh it
                }
                if (!self::unitObjectIsSafeToLink($unitDir) || !is_file($unitDir.'/unit.bc')) {
                    continue;
                }
                if (!isset($manifest['init_symbol']) || '' === (string) $manifest['init_symbol']) {
                    continue; // pre-init-era unit: its module state never runs — unusable (#16075 step 4)
                }
                if (isset($manifest['runtime_safe']) && false === $manifest['runtime_safe']) {
                    continue; // known cross-module ABI hazard (baked class ids) — see emitter blocklist
                }
                foreach ($manifest['helpers'] as $logical => $symbol) {
                    if (isset($index[$logical])) {
                        continue; // build cache outranks prelinked
                    }
                    $index[$logical] = [
                        'symbol' => (string) $symbol,
                        'dir' => $unitDir,
                        'init' => (string) $manifest['init_symbol'],
                        'shutdown' => isset($manifest['shutdown_symbol']) ? (string) $manifest['shutdown_symbol'] : null,
                        'init_via_global_ctor' => !empty($manifest['init_via_global_ctor']),
                    ];
                }
            }
        }

        return self::$helperIndex = $index;
    }

    public static function resolveUnitSource(string $root, string $unitPath): ?string
    {
        if (str_starts_with($unitPath, '/ext/') || str_starts_with($unitPath, '/lib/')) {
            $abs = $root.$unitPath;
        } else {
            $abs = $root.'/lib'.$unitPath;
        }

        return is_file($abs) ? $abs : null;
    }

    /**
     * Bind every cached helper among $logicalNames into $context->functions as
     * an extern declaration with the exact type from the unit's bitcode.
     *
     * @param list<string> $logicalNames
     */
    public static function tryProvide(Context $context, array $logicalNames): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $index = self::helperIndex();
        $lib = $context->llvm->lib;
        $bound = 0;
        foreach ($logicalNames as $logical) {
            $lc = strtolower($logical);
            if (self::shouldInlineOnlyForUserScript($lc)) {
                continue;
            }
            if (isset($context->functions[$lc]) || !isset($index[$lc])) {
                continue;
            }
            $symbol = $index[$lc]['symbol'];
            $unitDir = $index[$lc]['dir'];

            $existing = $context->module->getNamedFunction($symbol);
            if (null !== $existing) {
                $context->functions[$lc] = $existing;
                self::wireUnitLifecycle($context, $index[$lc]);
                self::$usedUnits[$unitDir] = true;
                ++$bound;

                continue;
            }

            $parsed = self::parsedUnit($context, $unitDir);
            if (null === $parsed) {
                continue;
            }
            $source = $parsed->getNamedFunction($symbol);
            if (null === $source) {
                continue;
            }
            $fnType = $lib->LLVMGetElementType($lib->LLVMTypeOf($source->value));
            if (null === $fnType) {
                continue;
            }
            // Parsing unit bitcode into a context that already defines the
            // named structs re-suffixes them (__string__ -> __string__.12);
            // declarations bound with suffixed types fail module verify at the
            // call sites. Rebuild the type against the LOCAL named structs.
            $type = self::localizedFunctionType($context, $source, $fnType)
                ?? $context->llvm->factory->type($context->context, $fnType);
            $context->functions[$lc] = $context->module->addFunction($symbol, $type);
            self::wireUnitLifecycle($context, $index[$lc]);
            self::$usedUnits[$unitDir] = true;
            ++$bound;
        }

        if ($bound > 0 && !self::$loggedHit) {
            $user = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');
            if ('1' === $user || 'true' === strtolower((string) $user)) {
                if (\defined('STDERR') && \is_resource(STDERR)) {
                    fwrite(STDERR, sprintf(
                        "phpc build: helper-runtime cache hit (%d helpers, core=%s) (#15889)\n",
                        $bound,
                        self::coreFingerprint()
                    ));
                }
                self::$loggedHit = true;
            }
        }

        return $bound > 0;
    }

    /**
     * Declare an extern from a producer chunk's bitcode (#36155 Phase C).
     *
     * Mirrors {@see tryProvide} but reads an explicit bitcode file instead of the
     * helper-runtime index — used when a consumer chunk binds cross-TU via manifest.
     */
    public static function declareExternFromBitcode(Context $context, string $symbol, string $bitcodePath): ?object
    {
        if ('' === $symbol || '' === $bitcodePath || !is_file($bitcodePath)) {
            return null;
        }
        $existing = $context->module->getNamedFunction($symbol);
        if (null !== $existing) {
            return $existing;
        }
        $parsed = self::parsedBitcodeFile($context, $bitcodePath);
        if (null === $parsed) {
            return null;
        }
        $source = $parsed->getNamedFunction($symbol);
        if (null === $source) {
            return null;
        }
        $lib = $context->llvm->lib;
        $fnType = $lib->LLVMGetElementType($lib->LLVMTypeOf($source->value));
        if (null === $fnType) {
            return null;
        }
        $type = self::localizedFunctionType($context, $source, $fnType)
            ?? $context->llvm->factory->type($context->context, $fnType);

        return $context->module->addFunction($symbol, $type);
    }

    /**
     * Function type rebuilt from the local context's named structs, or null
     * when any component type is unknown locally (caller falls back to the
     * parsed type verbatim).
     */
    private static function localizedFunctionType(Context $context, object $source, object $fnType): ?object
    {
        $lib = $context->llvm->lib;
        try {
            $params = [];
            for ($i = 0, $n = $source->countParams(); $i < $n; ++$i) {
                $params[] = self::localizedType($context, $lib->LLVMTypeOf($source->getParam($i)->value));
            }
            $ret = self::localizedType($context, $lib->LLVMGetReturnType($fnType));
            if (null === $ret || \in_array(null, $params, true)) {
                return null;
            }

            return $context->context->functionType($ret, false, ...$params);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Localize one raw FFI type: named structs (possibly context-suffixed,
     * __string__.12) map to the local struct of the base name at the same
     * pointer depth; everything else wraps verbatim.
     */
    private static function localizedType(Context $context, object $rawTy): ?object
    {
        $lib = $context->llvm->lib;
        $depth = 0;
        $t = $rawTy;
        while (\llvm\llvm::LLVMPointerTypeKind === $lib->LLVMGetTypeKind($t)) {
            $t = $lib->LLVMGetElementType($t);
            ++$depth;
        }
        if (\llvm\llvm::LLVMStructTypeKind === $lib->LLVMGetTypeKind($t)) {
            $name = $lib->LLVMGetStructName($t);
            $name = \is_object($name) ? $name->toString() : (string) $name;
            if ('' === $name) {
                return null; // anonymous struct — no local identity to map to
            }
            $base = (string) preg_replace('/\\.\\d+$/', '', $name);

            try {
                return $context->getTypeFromString($base.str_repeat('*', $depth));
            } catch (\Throwable) {
                return null;
            }
        }

        return $context->llvm->factory->type($context->context, $rawTy);
    }

    /** @var array<string, true> unit dir → lifecycle calls already wired */
    private static array $wiredLifecycles = [];

    /**
     * First use of a unit: the consuming script's __init__/__shutdown__ call
     * the unit's uniquely-named init/shutdown (the colliding __init__ symbols
     * were muldefs-discarded and unit module state never ran, #16075 step 4).
     * Units emitted before init symbols existed have no manifest entry and
     * keep the old (uninitialized) behavior.
     *
     * @param array{symbol: string, dir: string, init: ?string, shutdown: ?string, init_via_global_ctor?: bool} $entry
     */
    private static function wireUnitLifecycle(Context $context, array $entry): void
    {
        $unitDir = $entry['dir'];
        if (isset(self::$wiredLifecycles[$unitDir])) {
            return;
        }
        self::$wiredLifecycles[$unitDir] = true;
        if (!empty($entry['init_via_global_ctor'])) {
            // Unit init runs via llvm.global_ctors at load time (#16075 step 4).
            return;
        }
        $voidFn = static function (string $name) use ($context): object {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                return $fn;
            }

            return $context->module->addFunction(
                $name,
                $context->context->functionType($context->context->voidType(), false)
            );
        };
        // Legacy units without global ctors: user-script AOT must skip emitInInit
        // wiring — calling unit inits from script __init__ aliases muldefs-merged
        // globals (#17069).
        $userAot = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        $skipInit = '1' === $userAot || 'true' === strtolower((string) $userAot);
        if (!$skipInit && null !== $entry['init'] && '' !== $entry['init']) {
            $initFn = $voidFn($entry['init']);
            $context->emitInInit(static function (Context $ctx) use ($initFn): void {
                $ctx->builder->call($initFn);
            });
        }
        // Deliberately NOT wiring the unit's __shutdown__: after -z muldefs
        // symbol unification the unit's globals partially alias the script's,
        // and running both shutdowns double-frees (SIGABRT at exit). Leaking
        // at process end matches the previous behavior and is safe.
    }

    private static function parsedUnit(Context $context, string $unitDir): ?object
    {
        if (isset(self::$parsedUnits[$unitDir])) {
            return self::$parsedUnits[$unitDir];
        }
        $parsed = self::parsedBitcodeFile($context, $unitDir.'/unit.bc');
        if (null !== $parsed) {
            self::$parsedUnits[$unitDir] = $parsed;
        }

        return $parsed;
    }

    private static function parsedBitcodeFile(Context $context, string $path): ?object
    {
        if (isset(self::$parsedBitcodeFiles[$path])) {
            return self::$parsedBitcodeFiles[$path];
        }
        $data = is_file($path) ? (string) file_get_contents($path) : '';
        if ('' === $data) {
            return null;
        }
        // createMemoryBufferWithString instead of ...WithFile: the vendored
        // ...WithFile references an unimported FFI class (latent php-llvm bug).
        $buffer = $context->llvm->createMemoryBufferWithString($data, basename($path));

        try {
            // Kept referenced for the process lifetime — declaration types
            // point into the shared LLVMContext.
            return self::$parsedBitcodeFiles[$path] = $buffer->parseBitcode($context->context);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Linker hook: unit objects whose helpers were bound in this build.
     *
     * @return list<string>
     */
    public static function linkObjects(): array
    {
        if (!self::enabled() || [] === self::$usedUnits) {
            return [];
        }
        $objects = [];
        $common = HelperRuntimeCommon::linkObject();
        if (null !== $common) {
            $objects[] = $common;
        }
        // Discovery order follows first-use; sort unit paths so two builds with the same
        // helper set produce identical ld argument lists (#36399 / build-id=sha1).
        $unitObjects = [];
        foreach (array_keys(self::$usedUnits) as $unitDir) {
            $object = $unitDir.'/unit.o';
            if (self::unitObjectIsSafeToLink($unitDir)) {
                $unitObjects[] = $object;
            }
        }
        sort($unitObjects, SORT_STRING);

        return array_merge($objects, $unitObjects);
    }

    /**
     * Basenames of helper units currently selected for link (#36387 object mid-tier).
     *
     * Sorted by slug so mid-tier restore / slugs JSON is byte-stable across runs (#36399).
     *
     * @return list<string>
     */
    public static function usedUnitSlugs(): array
    {
        $slugs = [];
        foreach (array_keys(self::$usedUnits) as $unitDir) {
            $slug = basename((string) $unitDir);
            if ('' !== $slug) {
                $slugs[] = $slug;
            }
        }
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /**
     * Rebuild {@see $usedUnits} from cached slugs so {@see linkObjects()} works without
     * a fresh lowering pass (#36387 mid-tier `.o` restore).
     *
     * @param list<string> $slugs
     */
    public static function adoptUnitSlugsForLink(array $slugs): void
    {
        self::$usedUnits = [];
        foreach ($slugs as $slug) {
            if (!is_string($slug) || '' === $slug) {
                continue;
            }
            $dir = self::resolveLinkableUnitDir($slug);
            if (null !== $dir) {
                self::$usedUnits[$dir] = true;
            }
        }
    }

    /**
     * Local tier first, then committed prelinked tier (#36387).
     */
    public static function resolveLinkableUnitDir(string $slug): ?string
    {
        foreach ([self::unitDir($slug), self::prelinkedUnitsDir().'/'.$slug] as $dir) {
            if (self::unitObjectIsSafeToLink($dir)) {
                return $dir;
            }
        }

        return null;
    }

    public static function markEmitting(): void
    {
        putenv(self::ENV_EMITTING.'=1');
    }

    /**
     * A zero-byte unit.o can exist when emit was interrupted; it must not shadow the
     * committed prelinked tier or link as an empty object (undefined helper symbols, #6229).
     */
    public static function unitObjectIsLinkable(string $unitDir): bool
    {
        $object = $unitDir.'/unit.o';

        return is_file($object) && filesize($object) > 0;
    }

    /**
     * Whether a unit.o may participate in an AOT link under HELPER_RUNTIME_O=1.
     *
     * Per-function-section units ({@see \PHPCompiler\JIT\AotGcSections}) require
     * {@see HelperRuntimeCommon} in the link. Without it, `bin/compile.php` (which
     * defaults HELPER_RUNTIME_O=1) produces SIGSEGV on every binary — measured
     * aot-smoke 0/9 exit 139 after an accidental gc_sections prelink (#36246 / #36401).
     * Skip those units so NestedJIT fills the gap until COMMON is opted in.
     */
    public static function unitObjectIsSafeToLink(string $unitDir): bool
    {
        if (!self::unitObjectIsLinkable($unitDir)) {
            return false;
        }
        if (HelperRuntimeCommon::isLinkEnabled()) {
            return true;
        }
        // Fast path: committed corpus without gc_sections → monolithic .text, no readelf.
        $prelinkedRoot = self::prelinkedUnitsDir();
        if (is_string($prelinkedRoot) && '' !== $prelinkedRoot
            && str_starts_with($unitDir, $prelinkedRoot)
            && !self::prelinkedCorpusHasGcSections()) {
            return true;
        }

        return !self::unitObjectHasPerFunctionSections($unitDir.'/unit.o');
    }

    /**
     * Why a built unit.o must not be published into the committed prelinked tree.
     *
     * Mixed gc_sections objects into a monolithic corpus (without COMMON) made
     * HELPER_RUNTIME_O=1 AOT binaries SIGSEGV — aot-smoke 0/9 (#36246 / #36401).
     *
     * @return string|null null when publish is allowed
     */
    public static function refusePrelinkGcMixReason(string $unitObjectPath, bool $migrateToGcSections = false): ?string
    {
        if (!self::unitObjectHasPerFunctionSections($unitObjectPath)) {
            return null;
        }
        if (!HelperRuntimeCommon::commonObjectIsLinkable()) {
            return 'gc_sections unit.o needs linkable common.o before publish';
        }
        if (!self::prelinkedCorpusHasGcSections() && !$migrateToGcSections) {
            return 'gc_sections unit.o into monolithic corpus';
        }

        return null;
    }

    /**
     * True when $objectPath carries AotGcSections per-function ELF sections (.text.<symbol>).
     *
     * Monolithic .text units duplicate runtime symbols that common.o cannot gc (#36246).
     */
    public static function unitObjectHasPerFunctionSections(string $objectPath): bool
    {
        if (!is_file($objectPath) || filesize($objectPath) <= 0) {
            return false;
        }
        $out = [];
        exec(
            'readelf -S '.escapeshellarg($objectPath).' 2>/dev/null | grep -c "\.text\."',
            $out,
            $rc
        );
        if (0 !== $rc || !isset($out[0])) {
            return false;
        }

        return (int) $out[0] > 0;
    }

    /**
     * Committed prelinked corpus was emitted with AotGcSections (per-function .text.* sections).
     *
     * Required before {@see HelperRuntimeCommon} links common.o by default — otherwise
     * -z muldefs keeps duplicate monolithic .text bodies and binaries grow (#36423).
     */
    public static function prelinkedCorpusHasGcSections(): bool
    {
        $manifestPath = \dirname(self::prelinkedUnitsDir()).'/manifest.json';
        if (is_file($manifestPath)) {
            $decoded = json_decode((string) file_get_contents($manifestPath), true);
            if (\is_array($decoded) && !empty($decoded['gc_sections'])) {
                return true;
            }
        }
        $anchor = self::prelinkedUnitsDir().'/'.self::slugFor('/ext/ctype/CtypeJitHelper.php').'/unit.o';

        return self::unitObjectHasPerFunctionSections($anchor);
    }

    private static function shouldInlineOnlyForUserScript(string $logicalLc): bool
    {
        if (!isset(self::USER_SCRIPT_INLINE_ONLY_LOGICALS[$logicalLc])) {
            return false;
        }
        $user = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');

        return '1' === $user || 'true' === strtolower((string) $user);
    }
}
