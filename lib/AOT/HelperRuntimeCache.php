<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\Context;

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
 * Global inputs ({@see coreFingerprint}) are only composer.lock, patches, and
 * LLVM library identity (#24381) — content hash of libLLVM-9.so.1, not the
 * install path string, so host `.llvm` and Docker `/opt/llvm9` with the same
 * bytes share a fingerprint. Editing lib/JIT.php no longer invalidates the
 * whole corpus. Emit records the NestedJIT closure in deps[]; editing one
 * reached lowering invalidates only units that listed it. Legacy manifests
 * keep the old JIT-core key until re-emitted so the committed prelinked tier
 * stays usable.
 *
 * Opt-in: PHP_COMPILER_HELPER_RUNTIME_O=1.
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
        // #23912 — force NestedJIT of NestedJIT-safe StrReplaceJitHelper into user AOT
        // (stale/empty helper unit.o otherwise returns "" / wrong bytes for scalar replace).
        'phpcompiler\\ext\\standard\\strreplacejithelper::replaceargv' => true,
        'phpcompiler\\ext\\standard\\strreplacejithelper::ireplaceargv' => true,
        'phpcompiler\\ext\\standard\\strreplacejithelper::takelastcount' => true,
        // #27564 / re-#26827 — helper-runtime PregQuoteJitHelper unit.o returns "" under
        // default cache hit; NestedJIT of the inline-escape helper matches VM/JIT (O=0 OK).
        'phpcompiler\\ext\\standard\\pregquotejithelper::pregquoteargv' => true,
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
        // #30812 — prelinked WordwrapJitHelper unit.o SIGSEGVs under thin AOT; NestedJIT
        // VmWordwrap (strlen/substr) into the user module (peer #30790 / #30811).
        'phpcompiler\\ext\\standard\\wordwrapjithelper::wordwrapargv' => true,
        // #30859 / re-#26992 — prelinked ChunkSplitJitHelper unit.o SIGSEGVs under thin AOT;
        // NestedJIT VmChunkSplit (strlen/substr) into the user module (peer #30811).
        'phpcompiler\\ext\\standard\\chunksplitjithelper::chunksplitargv' => true,
        // #30858 / re-#27011 — prelinked QuotemetaJitHelper unit.o SIGSEGVs under thin AOT;
        // NestedJIT VmQuotemeta (strlen/substr) into the user module (peer #30859).
        'phpcompiler\\ext\\standard\\quotemetajithelper::quotemetaargv' => true,
        // #30813 — prelinked Nl2brJitHelper unit.o SIGSEGVs under thin AOT; NestedJIT
        // VmNl2br (strlen/substr) into the user module (peer #30812 / #30859).
        'phpcompiler\\ext\\standard\\nl2brjithelper::nl2brargv' => true,
        // #31099 — NestedJIT UrlRewriterApply during emitAdd only (not Context init);
        // prelinked unit.o not used for user-script rewrite apply.
        'phpcompiler\\ext\\standard\\urlrewriterapplyjithelper::applyargv' => true,
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
        $user = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
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

    /**
     * Global inputs only (#23458 / #24381): composer.lock, patches, LLVM library.
     *
     * Deliberately excludes lib/JIT.php / Context.php / Runtime.php — those change
     * most days and were switching the whole corpus off. Per-unit deps[] cover the
     * NestedJIT closure instead. Content hashes (not mtime) so committed prelinked
     * units stay shareable across clones. LLVM is identified by libLLVM-9.so.1
     * bytes (#24381), not the install path, so host `.llvm` and Docker `/opt/llvm9`
     * with identical libraries share one fingerprint.
     */
    public static function coreFingerprint(): string
    {
        static $core = null;
        if (null !== $core) {
            return $core;
        }

        return $core = substr(hash('sha256', self::globalFingerprintMaterial()), 0, 20);
    }

    /**
     * Pre-#23458 lowering-machinery key — must match the old coreFingerprint()
     * byte-for-byte so committed manifests without deps[] stay fresh.
     */
    public static function legacyLoweringFingerprint(): string
    {
        static $legacy = null;
        if (null !== $legacy) {
            return $legacy;
        }
        $root = \dirname(__DIR__, 2);
        $parts = [(string) getenv('PHP_COMPILER_LLVM_PATH')];
        foreach ([
            $root.'/composer.lock',
            $root.'/lib/JIT.php',
            $root.'/lib/JIT/Context.php',
            $root.'/lib/Runtime.php',
            $root.'/lib/JIT/JitVmHelperLink.php',
            $root.'/script/apply-patches.sh',
        ] as $file) {
            $parts[] = substr($file, \strlen($root)).':'.@hash_file('sha256', $file);
        }
        $patchFiles = glob($root.'/patches/*.patch') ?: [];
        sort($patchFiles, SORT_STRING);
        foreach ($patchFiles as $patch) {
            $parts[] = substr($patch, \strlen($root)).':'.@hash_file('sha256', $patch);
        }

        return $legacy = substr(hash('sha256', implode("\n", $parts)), 0, 20);
    }

    /**
     * Stable LLVM identity for the global fingerprint (#24381).
     *
     * Prefer hashing libLLVM-9.so.1 at PHP_COMPILER_LLVM_PATH when present; if the
     * env path has no library, fall back to a path token so distinct missing installs
     * still diverge. Never fall through to another install dir when the env path is
     * set — that would hide an intentional LLVM_PATH override.
     */
    public static function llvmIdentityToken(): string
    {
        static $token = null;
        if (null !== $token) {
            return $token;
        }
        $env = (string) getenv('PHP_COMPILER_LLVM_PATH');
        if ('' !== $env) {
            $so = rtrim($env, '/').'/libLLVM-9.so.1';
            if (is_file($so)) {
                return $token = 'lib:'.(string) hash_file('sha256', $so);
            }

            return $token = 'path:'.$env;
        }
        $root = \dirname(__DIR__, 2);
        foreach ([$root.'/.llvm', '/opt/llvm9'] as $dir) {
            $so = $dir.'/libLLVM-9.so.1';
            if (is_file($so)) {
                return $token = 'lib:'.(string) hash_file('sha256', $so);
            }
        }

        return $token = 'path:';
    }

    /**
     * Live core fingerprint plus pre-#24381 path-keyed cores that hash the same libLLVM (#24381).
     *
     * Committed caches built with `/opt/llvm9` must stay fresh on a host whose
     * `PHP_COMPILER_LLVM_PATH` points at an identical `.llvm` tree.
     *
     * @return list<string>
     */
    public static function equivalentCoreFingerprints(): array
    {
        static $list = null;
        if (null !== $list) {
            return $list;
        }
        $cores = [self::coreFingerprint()];
        $liveLib = self::llvmLibSha256OrNull();
        if (null === $liveLib) {
            // No LLVM on host — try to reconstruct the Docker fingerprint from
            // the committed manifest's llvm_identity_token so --strict checks
            // pass on hosts without LLVM (#24302).
            $root = \dirname(__DIR__, 2);
            $archDir = $root.'/prelinked/helper-runtime/'.php_uname('m').'-'.strtolower(php_uname('s'));
            $mfPath = $archDir.'/manifest.json';
            if (is_file($mfPath)) {
                $mf = json_decode((string) file_get_contents($mfPath), true);
                $tok = \is_array($mf) ? (string) ($mf['llvm_identity_token'] ?? '') : '';
                if ('' !== $tok && $tok !== self::llvmIdentityToken()) {
                    $cores[] = self::coreFingerprintWithLlvmToken($tok);
                }
            }
            return $list = array_values(array_unique($cores));
        }
        $root = \dirname(__DIR__, 2);
        foreach (array_unique(array_filter([
            (string) getenv('PHP_COMPILER_LLVM_PATH'),
            $root.'/.llvm',
            '/opt/llvm9',
        ])) as $dir) {
            $so = rtrim($dir, '/').'/libLLVM-9.so.1';
            if (is_file($so)) {
                if (hash_file('sha256', $so) !== $liveLib) {
                    continue;
                }
                $cores[] = self::coreFingerprintWithLlvmToken($dir);
                continue;
            }
            // Host often has only `.llvm`; Docker only `/opt/llvm9`. When the live
            // lib identity is known, also accept the other canonical path token so
            // committed caches keyed on either install string stay fresh (#24381).
            if ('/opt/llvm9' === $dir || str_ends_with(rtrim($dir, '/'), '/.llvm')) {
                $cores[] = self::coreFingerprintWithLlvmToken($dir);
            }
        }

        return $list = array_values(array_unique($cores));
    }

    public static function coreFingerprintMatches(string $candidate): bool
    {
        return \in_array($candidate, self::equivalentCoreFingerprints(), true);
    }

    private static function llvmLibSha256OrNull(): ?string
    {
        $token = self::llvmIdentityToken();
        if (str_starts_with($token, 'lib:')) {
            return substr($token, 4);
        }

        return null;
    }

    /** Pre-#24381 material: LLVM install path string + lock + patches. */
    private static function coreFingerprintWithLlvmToken(string $llvmToken): string
    {
        $root = \dirname(__DIR__, 2);
        $parts = [$llvmToken];
        foreach ([
            $root.'/composer.lock',
            $root.'/script/apply-patches.sh',
        ] as $file) {
            $parts[] = substr($file, \strlen($root)).':'.@hash_file('sha256', $file);
        }
        $patchFiles = glob($root.'/patches/*.patch') ?: [];
        sort($patchFiles, SORT_STRING);
        foreach ($patchFiles as $patch) {
            $parts[] = substr($patch, \strlen($root)).':'.@hash_file('sha256', $patch);
        }

        return substr(hash('sha256', implode("\n", $parts)), 0, 20);
    }

    private static function globalFingerprintMaterial(): string
    {
        $root = \dirname(__DIR__, 2);
        $parts = [self::llvmIdentityToken()];
        foreach ([
            $root.'/composer.lock',
            $root.'/script/apply-patches.sh',
        ] as $file) {
            $parts[] = substr($file, \strlen($root)).':'.@hash_file('sha256', $file);
        }
        $patchFiles = glob($root.'/patches/*.patch') ?: [];
        sort($patchFiles, SORT_STRING);
        foreach ($patchFiles as $patch) {
            $parts[] = substr($patch, \strlen($root)).':'.@hash_file('sha256', $patch);
        }

        return implode("\n", $parts);
    }

    /** Architecture key for shareable prelinked unit objects, e.g. "x86_64-linux". */
    public static function archKey(): string
    {
        return php_uname('m').'-'.strtolower(php_uname('s'));
    }

    /** Committed per-arch unit cache: prelinked/helper-runtime/<arch>/units. */
    public static function prelinkedUnitsDir(): string
    {
        return \dirname(__DIR__, 2).'/prelinked/helper-runtime/'.self::archKey().'/units';
    }

    /**
     * Repo-root relative path (/lib/… or /ext/…) for an absolute file, or null.
     */
    public static function repoRelPath(string $absPath): ?string
    {
        $root = \dirname(__DIR__, 2);
        $real = realpath($absPath) ?: $absPath;
        $real = str_replace('\\', '/', $real);
        $rootNorm = str_replace('\\', '/', $root);
        if (!str_starts_with($real, $rootNorm.'/')) {
            return null;
        }
        $rel = substr($real, \strlen($rootNorm));
        if (str_starts_with($rel, '/lib/') || str_starts_with($rel, '/ext/')) {
            return $rel;
        }

        return null;
    }

    /**
     * Build the NestedJIT dependency list recorded at emit time (#23458).
     *
     * @param list<string> $compiledAbsPaths from Context::listJitCompiledIncludePaths()
     *
     * @return list<string> sorted unique repo-relative paths
     */
    public static function dependencyRelPathsForEmit(string $unitSourceAbsPath, array $compiledAbsPaths): array
    {
        $rels = [];
        $unitRel = self::repoRelPath($unitSourceAbsPath);
        if (null !== $unitRel) {
            $rels[$unitRel] = true;
        }
        foreach ($compiledAbsPaths as $abs) {
            $rel = self::repoRelPath((string) $abs);
            if (null !== $rel) {
                $rels[$rel] = true;
            }
        }
        foreach (self::unitExtraDependencyRelPaths($unitSourceAbsPath) as $rel) {
            $rels[$rel] = true;
        }
        // One-level same-directory class refs from the unit + NestedJIT'd files only
        // (do not recurse through VmString → half of ext/standard).
        $seed = array_keys($rels);
        foreach ($seed as $rel) {
            foreach (self::sameDirClassReferenceRelPaths($rel) as $ref) {
                $rels[$ref] = true;
            }
        }
        $keys = array_keys($rels);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @return list<string> /lib|ext/.../Foo.php paths referenced as Foo:: in $rel
     */
    private static function sameDirClassReferenceRelPaths(string $rel): array
    {
        $root = \dirname(__DIR__, 2);
        $abs = $root.$rel;
        if (!is_file($abs)) {
            return [];
        }
        $code = (string) @file_get_contents($abs);
        if ('' === $code || !preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::/', $code, $m)) {
            return [];
        }
        $dir = str_replace('\\', '/', \dirname($rel));
        $out = [];
        foreach (array_unique($m[1]) as $class) {
            $candidate = $dir.'/'.$class.'.php';
            if (is_file($root.$candidate)) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * @param list<string>|null $depsRelPaths repo-relative paths; null = v2 with unit-only + extras
     */
    public static function unitFingerprint(string $unitSourceAbsPath, ?array $depsRelPaths = null): string
    {
        if (null === $depsRelPaths) {
            $depsRelPaths = self::dependencyRelPathsForEmit($unitSourceAbsPath, []);
        }

        return self::fingerprintV2($unitSourceAbsPath, $depsRelPaths);
    }

    /**
     * Fingerprint expected for an on-disk manifest (v2 deps[] or legacy v1).
     *
     * @param array{fingerprint?: string, unit?: string, deps?: list<string>|mixed} $manifest
     */
    public static function expectedFingerprintForManifest(array $manifest, string $unitSourceAbsPath): string
    {
        if (isset($manifest['deps']) && \is_array($manifest['deps'])) {
            $deps = [];
            foreach ($manifest['deps'] as $dep) {
                if (\is_string($dep) && '' !== $dep) {
                    $deps[] = $dep;
                }
            }

            return self::fingerprintV2($unitSourceAbsPath, $deps);
        }

        return self::fingerprintV1Legacy($unitSourceAbsPath);
    }

    public static function manifestFingerprintMatches(array $manifest, string $unitSourceAbsPath): bool
    {
        if (!isset($manifest['fingerprint'])) {
            return false;
        }
        $stored = (string) $manifest['fingerprint'];
        if ($stored === self::expectedFingerprintForManifest($manifest, $unitSourceAbsPath)) {
            return true;
        }
        // #24381: unit fps embed coreFingerprint; accept path-keyed cores that
        // identify the same libLLVM-9.so.1 bytes as the live install.
        if (!isset($manifest['deps']) || !\is_array($manifest['deps'])) {
            return false;
        }
        $deps = [];
        foreach ($manifest['deps'] as $dep) {
            if (\is_string($dep) && '' !== $dep) {
                $deps[] = $dep;
            }
        }
        foreach (self::equivalentCoreFingerprints() as $core) {
            if ($stored === self::fingerprintV2WithCore($unitSourceAbsPath, $deps, $core)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rewrite a legacy (no deps[]) manifest to v2 using static NestedJIT-ish deps (#23458).
     * Keeps unit.o / unit.bc; only updates fingerprint + deps. Returns null when not legacy-fresh.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>|null
     */
    public static function migrateManifestToV2(array $manifest, string $unitSourceAbsPath): ?array
    {
        $isV2 = isset($manifest['deps']) && \is_array($manifest['deps']);
        if (!$isV2 && !self::manifestFingerprintMatches($manifest, $unitSourceAbsPath)) {
            return null; // stale legacy — needs full re-emit
        }
        // Always recompute static deps (one-level) so migrate can shrink a prior over-expansion.
        $deps = self::dependencyRelPathsForEmit($unitSourceAbsPath, []);
        $manifest['deps'] = $deps;
        $manifest['fingerprint'] = self::fingerprintV2($unitSourceAbsPath, $deps);
        $manifest['fingerprint_version'] = 2;

        return $manifest;
    }

    /**
     * @param list<string> $depsRelPaths
     */
    public static function fingerprintV2(string $unitSourceAbsPath, array $depsRelPaths): string
    {
        return self::fingerprintV2WithCore($unitSourceAbsPath, $depsRelPaths, self::coreFingerprint());
    }

    /**
     * @param list<string> $depsRelPaths
     */
    public static function fingerprintV2WithCore(string $unitSourceAbsPath, array $depsRelPaths, string $core): string
    {
        $root = \dirname(__DIR__, 2);
        $source = @file_get_contents($unitSourceAbsPath);
        $parts = [
            $core,
            'v2',
            (string) $source,
        ];
        $deps = array_values(array_unique(array_filter($depsRelPaths, static fn ($d) => \is_string($d) && '' !== $d)));
        sort($deps, SORT_STRING);
        foreach ($deps as $rel) {
            $parts[] = $rel.':'.@hash_file('sha256', $root.$rel);
        }

        return substr(hash('sha256', implode("\n", $parts)), 0, 20);
    }

    private static function fingerprintV1Legacy(string $unitSourceAbsPath): string
    {
        $source = @file_get_contents($unitSourceAbsPath);
        $material = self::legacyLoweringFingerprint()."\n".(string) $source;
        $extra = self::unitDependencyFingerprintMaterial($unitSourceAbsPath);
        if ('' !== $extra) {
            $material .= "\n".$extra;
        }

        return substr(hash('sha256', $material), 0, 20);
    }

    /**
     * Nested helper units embed ext/dom semantics pulled in at emit time; hash SSOT
     * alongside the helper stub so VmDom edits invalidate stale units (#17954).
     *
     * Float math *JitHelper units NestedJIT through {@see \PHPCompiler\ext\standard\JitFdiv}
     * boxed-double lowering — hash it so JitFdiv edits invalidate those units (#20651).
     *
     * @return list<string>
     */
    private static function unitExtraDependencyRelPaths(string $unitSourceAbsPath): array
    {
        $parts = [];
        $root = \dirname(__DIR__, 2);
        if (str_starts_with($unitSourceAbsPath, $root.'/ext/dom/')) {
            foreach ([
                '/ext/dom/VmDom.php',
                '/ext/dom/VmDomJitFrame.php',
                '/ext/dom/DomRegistry.php',
            ] as $rel) {
                $parts[] = $rel;
            }
        }
        $base = \basename($unitSourceAbsPath);
        if (1 === preg_match(
            '/^(Fpow|Nextafter|Sqrt|Hypot|Log|Log10|Log1p|Sin|Cos|Tan|Asin|Acos|Atan|Atan2|Sinh|Cosh|Tanh|Exp|Expm1|Floor|Ceil|Round|Fmod|Fdiv)JitHelper\.php$/',
            $base
        )) {
            $parts[] = '/ext/standard/JitFdiv.php';
        }

        return $parts;
    }

    /**
     * Legacy v1 extra material (content hashes) — kept for fingerprintV1Legacy.
     */
    private static function unitDependencyFingerprintMaterial(string $unitSourceAbsPath): string
    {
        $root = \dirname(__DIR__, 2);
        $parts = [];
        foreach (self::unitExtraDependencyRelPaths($unitSourceAbsPath) as $rel) {
            $parts[] = $rel.':'.@hash_file('sha256', $root.$rel);
        }

        return implode("\n", $parts);
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
                if (!is_file($unitDir.'/unit.o') || !is_file($unitDir.'/unit.bc')) {
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
            $user = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
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
     * Function type rebuilt from the local context's named structs, or null
     * when any component type is unknown locally (caller falls back to the
     * parsed type verbatim).
     */
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
        $userAot = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
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
        $path = $unitDir.'/unit.bc';
        $data = is_file($path) ? (string) file_get_contents($path) : '';
        if ('' === $data) {
            return null;
        }
        // createMemoryBufferWithString instead of ...WithFile: the vendored
        // ...WithFile references an unimported FFI class (latent php-llvm bug).
        $buffer = $context->llvm->createMemoryBufferWithString($data, basename($unitDir).'.bc');

        try {
            // Kept referenced for the process lifetime — declaration types
            // point into the shared LLVMContext.
            return self::$parsedUnits[$unitDir] = $buffer->parseBitcode($context->context);
        } catch (\Throwable $e) {
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
        foreach (array_keys(self::$usedUnits) as $unitDir) {
            $object = $unitDir.'/unit.o';
            if (is_file($object)) {
                $objects[] = $object;
            }
        }

        return $objects;
    }

    public static function markEmitting(): void
    {
        putenv(self::ENV_EMITTING.'=1');
    }

    private static function shouldInlineOnlyForUserScript(string $logicalLc): bool
    {
        if (!isset(self::USER_SCRIPT_INLINE_ONLY_LOGICALS[$logicalLc])) {
            return false;
        }
        $user = getenv('PHP_COMPILER_AOT_USER_SCRIPT');

        return '1' === $user || 'true' === strtolower((string) $user);
    }
}
