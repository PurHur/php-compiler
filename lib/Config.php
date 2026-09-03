<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Central registry for PHP_COMPILER_* (and related) environment knobs (#36201).
 *
 * Call sites in lib/ and bin/ use Config::getenv() / Config::get() / Config::isTruthy()
 * instead of raw getenv() for PHP_COMPILER_* names so unknown knobs can be warned and
 * `phpc doctor --env` can report origin + effective value. On the self-host spine via
 * `compiler_lib_spine_smoke/main.php` (required before Doctor.php).
 */
final class Config
{
    public const SCOPE_COMPILE = 'compile';
    public const SCOPE_RUNTIME = 'runtime';
    public const SCOPE_GATE = 'gate';
    public const SCOPE_DEBUG = 'debug';

    /** @var array<string, array{type: string, default: ?string, scope: string, description: string, since: ?string}>|null */
    private static ?array $registry = null;

    /** @var array<string, true> */
    private static array $seenUnknown = [];

    /** @var list<string> */
    private static array $warnedUnknown = [];

    /**
     * Drop-in for getenv() on registered (and dynamic PHP_COMPILER_ENABLE_*) knobs.
     * Returns false when unset, matching PHP getenv().
     */
    public static function getenv(string $name): string|false
    {
        self::noteRead($name);
        $value = \getenv($name);
        if (false === $value) {
            return false;
        }

        return (string) $value;
    }

    /**
     * Effective string value: env if set/non-empty, else registry default, else $default.
     */
    public static function get(string $name, ?string $default = null): ?string
    {
        $raw = self::getenv($name);
        if (false !== $raw && '' !== $raw) {
            return $raw;
        }
        $spec = self::registry()[$name] ?? null;
        if (null !== $spec && null !== $spec['default'] && '' !== $spec['default']) {
            return $spec['default'];
        }

        return $default;
    }

    public static function isTruthy(string $name): bool
    {
        return '1' === (string) self::getenv($name);
    }

    /**
     * @return array<string, array{type: string, default: ?string, scope: string, description: string, since: ?string}>
     */
    public static function registry(): array
    {
        if (null === self::$registry) {
            self::$registry = self::buildRegistry();
        }

        return self::$registry;
    }

    /**
     * Warn once per process about PHP_COMPILER_* names present in the environment
     * that are not in the registry (misspellings are otherwise silent).
     *
     * @return list<string> newly warned names this call
     */
    public static function warnUnknownPhpCompilerEnv(): array
    {
        $known = self::registry();
        $fresh = [];
        foreach (self::environmentPhpCompilerKeys() as $name) {
            if (isset($known[$name])) {
                continue;
            }
            if (str_starts_with($name, 'PHP_COMPILER_ENABLE_')) {
                continue;
            }
            if (isset(self::$seenUnknown[$name])) {
                continue;
            }
            self::$seenUnknown[$name] = true;
            self::$warnedUnknown[] = $name;
            $fresh[] = $name;
            fwrite(STDERR, "php-compiler: unknown environment variable {$name} (not in Config registry; check spelling) (#36201)\n");
        }

        return $fresh;
    }

    /**
     * @return list<string>
     */
    public static function warnedUnknown(): array
    {
        return self::$warnedUnknown;
    }

    /**
     * Resolve where the effective value came from for doctor --env.
     *
     * @return array{value: ?string, origin: string}
     */
    public static function resolve(string $name, string $repoRoot): array
    {
        $envRaw = \getenv($name);
        $spec = self::registry()[$name] ?? null;
        $ciDefault = self::ciDefaultsValue($repoRoot, $name);
        $dockerfile = self::dockerfileEnvValue($repoRoot, $name);

        if (false !== $envRaw && '' !== (string) $envRaw) {
            $origin = 'env';
            // Image ENV is indistinguishable from export once inside the process; surface
            // Dockerfile pin when it matches and ci-defaults would have differed.
            if (null !== $dockerfile && (string) $envRaw === $dockerfile
                && null !== $ciDefault && $ciDefault !== $dockerfile) {
                $origin = 'image-ENV (shadows ci-defaults)';
            }

            return ['value' => (string) $envRaw, 'origin' => $origin];
        }

        if (null !== $ciDefault && '' !== $ciDefault) {
            return ['value' => $ciDefault, 'origin' => 'ci-defaults.env'];
        }

        if (null !== $spec && null !== $spec['default']) {
            return ['value' => $spec['default'], 'origin' => 'registry-default'];
        }

        return ['value' => null, 'origin' => 'unset'];
    }

    /**
     * Compare Dockerfile ENV pins against script/ci-defaults.env defaults (#36201).
     *
     * @return list<array{name: string, dockerfile: string, ci_defaults: string}>
     */
    public static function dockerfileCiDefaultsDrift(string $repoRoot): array
    {
        $drift = [];
        foreach (self::registry() as $name => $spec) {
            $docker = self::dockerfileEnvValue($repoRoot, $name);
            if (null === $docker) {
                continue;
            }
            $ci = self::ciDefaultsValue($repoRoot, $name);
            if (null === $ci) {
                $ci = $spec['default'];
            }
            if (null !== $ci && $ci !== $docker) {
                $drift[] = [
                    'name' => $name,
                    'dockerfile' => $docker,
                    'ci_defaults' => $ci,
                ];
            }
        }

        return $drift;
    }

    private static function noteRead(string $name): void
    {
        if (!str_starts_with($name, 'PHP_COMPILER_')) {
            return;
        }
        if (isset(self::registry()[$name])) {
            return;
        }
        if (str_starts_with($name, 'PHP_COMPILER_ENABLE_')) {
            return;
        }
        // Defer warning aggregation to warnUnknownPhpCompilerEnv / doctor --env.
        self::$seenUnknown[$name] = true;
    }

    /**
     * @return list<string>
     */
    private static function environmentPhpCompilerKeys(): array
    {
        $keys = [];
        foreach ($_ENV as $k => $_) {
            if (is_string($k) && str_starts_with($k, 'PHP_COMPILER_')) {
                $keys[$k] = true;
            }
        }
        foreach ($_SERVER as $k => $_) {
            if (is_string($k) && str_starts_with($k, 'PHP_COMPILER_')) {
                $keys[$k] = true;
            }
        }

        return array_keys($keys);
    }

    private static function ciDefaultsValue(string $repoRoot, string $name): ?string
    {
        static $cache = null;
        if (null === $cache) {
            $cache = [];
            $path = $repoRoot.'/script/ci-defaults.env';
            if (is_file($path)) {
                $text = (string) file_get_contents($path);
                // export NAME="${NAME:-default}"  or  export NAME=literal
                if (preg_match_all('/^export\s+([A-Z0-9_]+)=(.*)$/m', $text, $m, PREG_SET_ORDER)) {
                    foreach ($m as $row) {
                        $raw = trim(explode('#', $row[2], 2)[0]);
                        if (
                            (str_starts_with($raw, '"') && str_ends_with($raw, '"'))
                            || (str_starts_with($raw, "'") && str_ends_with($raw, "'"))
                        ) {
                            $raw = substr($raw, 1, -1);
                        }
                        if (preg_match('/^\$\{[A-Z0-9_]+:-(.*)\}$/', $raw, $mm)) {
                            $cache[$row[1]] = $mm[1];
                        } else {
                            $cache[$row[1]] = $raw;
                        }
                    }
                }
            }
        }

        return $cache[$name] ?? null;
    }

    private static function dockerfileEnvValue(string $repoRoot, string $name): ?string
    {
        static $cache = null;
        if (null === $cache) {
            $cache = [];
            $path = $repoRoot.'/Docker/dev/ubuntu-22.04/Dockerfile';
            if (is_file($path)) {
                $text = (string) file_get_contents($path);
                if (preg_match_all('/^ENV\s+([A-Z0-9_]+)=(\S+)/m', $text, $m, PREG_SET_ORDER)) {
                    foreach ($m as $row) {
                        $cache[$row[1]] = $row[2];
                    }
                }
            }
        }

        return $cache[$name] ?? null;
    }

    /**
     * @return array<string, array{type: string, default: ?string, scope: string, description: string, since: ?string}>
     */
    private static function buildRegistry(): array
    {
        $bool = static fn (string $scope, string $desc, ?string $default = null, ?string $since = null): array => [
            'type' => 'bool',
            'default' => $default,
            'scope' => $scope,
            'description' => $desc,
            'since' => $since,
        ];
        $str = static fn (string $scope, string $desc, ?string $default = null, ?string $since = null): array => [
            'type' => 'string',
            'default' => $default,
            'scope' => $scope,
            'description' => $desc,
            'since' => $since,
        ];
        $int = static fn (string $scope, string $desc, ?string $default = null, ?string $since = null): array => [
            'type' => 'int',
            'default' => $default,
            'scope' => $scope,
            'description' => $desc,
            'since' => $since,
        ];

        return [
            'PHP_COMPILER_OPT_LEVEL' => $int(self::SCOPE_COMPILE, 'LLVM optimization level (0–3)', null, '#36213'),
            'PHP_COMPILER_OPT_SIZE_LEVEL' => $int(self::SCOPE_COMPILE, 'LLVM size optimization level', null, null),
            'PHP_COMPILER_HELPER_RUNTIME_O' => $str(self::SCOPE_COMPILE, 'Path to helper-runtime object / cache override', null, null),
            'PHP_COMPILER_TARGET' => $str(self::SCOPE_COMPILE, 'AOT/helper-cache target: x86_64-linux|aarch64-linux|aarch64-darwin', null, '#36391'),
            'PHP_COMPILER_CACHE' => $bool(self::SCOPE_COMPILE, 'Enable compile cache when 1', null, null),
            'PHP_COMPILER_CACHE_DIR' => $str(self::SCOPE_COMPILE, 'Compile cache directory', null, null),
            'PHP_COMPILER_SPINE_CHUNK' => $str(self::SCOPE_COMPILE, 'Split-TU spine chunk selector', null, '#36147'),
            'PHP_COMPILER_REPORT_EXTERNAL_STUBS' => $bool(self::SCOPE_DEBUG, 'Report unbound external method stubs', null, null),
            'PHP_COMPILER_WARN_EXTERNAL_STUBS' => $bool(self::SCOPE_DEBUG, 'Warn on external stubs', null, null),
            'PHP_COMPILER_FAIL_ON_EXTERNAL_STUBS' => $bool(self::SCOPE_DEBUG, 'Hard-fail on external stubs', null, null),
            'PHP_COMPILER_LLVM_ASSERT' => $bool(self::SCOPE_DEBUG, 'Enable php-llvm structGep/zExt asserts', null, '#16565'),
            'PHP_COMPILER_DUMP_IR' => $str(self::SCOPE_DEBUG, 'Dump LLVM IR path/flag', null, null),
            'PHP_COMPILER_EMIT_JOBS' => $int(self::SCOPE_COMPILE, 'Parallel helper-unit emit jobs', null, null),
            'PHP_COMPILER_MEMORY_LIMIT' => $str(self::SCOPE_RUNTIME, 'PHP memory_limit for compiler processes', '1536M', '#497'),
            'PHP_COMPILER_LLVM_MEMORY_LIMIT' => $str(self::SCOPE_COMPILE, 'PHP memory_limit for LLVM-heavy compiles', '8192M', '#24738'),
            'PHP_COMPILER_CI_RAM_GB' => $int(self::SCOPE_GATE, 'Host RAM budget advertised to CI wrappers', '8', '#497'),
            'PHP_COMPILER_DOCKER_MEM' => $str(self::SCOPE_GATE, 'Docker --memory for CI wrappers', '10g', '#497'),
            'PHP_COMPILER_DOCKER_MEM_SWAP' => $str(self::SCOPE_GATE, 'Docker --memory-swap for CI wrappers', '10g', '#497'),
            'PHP_COMPILER_VM_PEAK_RSS_MB' => $int(self::SCOPE_RUNTIME, 'VM child peak RSS guard (MiB)', '2048', '#497'),
            'PHP_COMPILER_VM_RSS_GUARD' => $bool(self::SCOPE_RUNTIME, 'Enable VM RSS guard', '1', '#497'),
            'PHP_COMPILER_LLVM_PATH' => $str(self::SCOPE_COMPILE, 'LLVM install prefix', null, null),
            'PHP_COMPILER_PHP' => $str(self::SCOPE_RUNTIME, 'PHP binary / argv override for phpc', null, null),
            'PHP_COMPILER_EXT_DIR' => $str(self::SCOPE_RUNTIME, 'Directory of PHP extension .so files', '/usr/lib/php/20220829', null),
            'PHP_COMPILER_REPO_ROOT' => $str(self::SCOPE_RUNTIME, 'Override repository root detection', null, null),
            'PHP_COMPILER_SKIP_SERVE_TESTS' => $bool(self::SCOPE_GATE, 'Skip @group serve tests (GHA-only)', null, null),
            'PHP_COMPILER_SELFHOST_AOT' => $bool(self::SCOPE_COMPILE, 'Self-host AOT emit mode', null, null),
            'PHP_COMPILER_VENDOR_PRELINK' => $bool(self::SCOPE_COMPILE, 'Use vendor prelinked objects', null, null),
            'PHP_COMPILER_AOT_USER_SCRIPT' => $str(self::SCOPE_COMPILE, 'User script path for AOT driver', null, null),
            'PHP_COMPILER_KEEP_OBJECT_FILE' => $str(self::SCOPE_DEBUG, 'Keep intermediate .o path', null, null),
            'PHP_COMPILER_EMIT_BITCODE' => $str(self::SCOPE_DEBUG, 'Emit LLVM bitcode path', null, null),
            'PHP_COMPILER_EMIT_HELPER_LINK' => $bool(self::SCOPE_COMPILE, 'Link helper runtime into emit', null, null),
            'PHP_COMPILER_INIT_SYMBOL_SUFFIX' => $str(self::SCOPE_COMPILE, 'Suffix for module init symbols', null, null),
            'PHP_COMPILER_DEBUG' => $bool(self::SCOPE_DEBUG, 'General debug flag', null, null),
            'PHP_COMPILER_DEBUG_LAST_PHASE' => $str(self::SCOPE_DEBUG, 'Record last compiler phase', null, null),
            'PHP_COMPILER_DEBUG_LAST_PHASE_FILE' => $str(self::SCOPE_DEBUG, 'Path for last-phase breadcrumb', null, null),
            'PHP_COMPILER_DEBUG_LAST_PHASE_STDERR' => $bool(self::SCOPE_DEBUG, 'Also print last phase to stderr', null, null),
            'PHP_COMPILER_DEBUG_LLVM_BLOCKS' => $bool(self::SCOPE_DEBUG, 'Dump LLVM block diagnostics', null, null),
            'PHP_COMPILER_DETACH_CFG_AFTER_JIT' => $bool(self::SCOPE_COMPILE, 'Detach CFG after JIT to free memory', null, null),
            'PHP_COMPILER_RELEASE_CFG_AFTER_COMPILE' => $bool(self::SCOPE_COMPILE, 'Release CFG after compile', null, null),
            'PHP_COMPILER_PROFILE' => $bool(self::SCOPE_DEBUG, 'Enable profiling', null, null),
            'PHP_COMPILER_AOT_COMPILE_PROFILE' => $bool(self::SCOPE_DEBUG, 'AOT compile profiling', null, null),
            'PHP_COMPILER_AOT_NO_FAST_EXIT' => $bool(self::SCOPE_COMPILE, 'Disable AOT fast-exit path', null, null),
            'PHP_COMPILER_BOOTSTRAP_AOT_LINK' => $bool(self::SCOPE_COMPILE, 'Bootstrap AOT link mode', null, null),
            'PHP_COMPILER_LINK_ALL_LIBS' => $bool(self::SCOPE_COMPILE, 'Link all shared libs unconditionally', null, null),
            'PHP_COMPILER_LINT_JOBS' => $int(self::SCOPE_COMPILE, 'Parallel lint jobs', null, null),
            'PHP_COMPILER_LINT_CACHE' => $bool(self::SCOPE_COMPILE, 'Enable lint cache', null, null),
            'PHP_COMPILER_LINT_CACHE_SALT' => $str(self::SCOPE_COMPILE, 'Lint cache salt', null, null),
            'PHP_COMPILER_LINT_FRONTEND_FAST' => $bool(self::SCOPE_COMPILE, 'Fast lint frontend', null, null),
            'PHP_COMPILER_BUNDLE_LINT_CACHE' => $bool(self::SCOPE_COMPILE, 'Bundle lint cache', null, null),
            'PHP_COMPILER_JIT_REQUIRE' => $bool(self::SCOPE_COMPILE, 'Require JIT (no VM fallback)', null, null),
            'PHP_COMPILER_JIT_LAZY_BUILTINS' => $bool(self::SCOPE_COMPILE, 'Lazy-load JIT builtins', null, null),
            'PHP_COMPILER_JIT_PROGRESS_FILE' => $str(self::SCOPE_DEBUG, 'JIT progress breadcrumb file', null, null),
            'PHP_COMPILER_PROGRESS_ABI' => $str(self::SCOPE_DEBUG, 'Progress ABI marker', null, null),
            'PHP_COMPILER_MAX_BODY' => $int(self::SCOPE_COMPILE, 'Max function body size guard', null, null),
            'PHP_COMPILER_MM_CACHE_INITIAL' => $int(self::SCOPE_RUNTIME, 'Initial memory manager cache', null, null),
            'PHP_COMPILER_FIBER_MAX_STACK_BYTES' => $int(self::SCOPE_RUNTIME, 'Fiber max stack bytes', null, null),
            'PHP_COMPILER_FIBER_MAX_STACK_FRAMES' => $int(self::SCOPE_RUNTIME, 'Fiber max stack frames', null, null),
            'PHP_COMPILER_EXTERNAL_STUBS_JSON' => $str(self::SCOPE_DEBUG, 'Write external stubs JSON report', null, null),
            'PHP_COMPILER_EXTERNAL_METHOD_MANIFEST_EXPORT' => $str(self::SCOPE_DEBUG, 'Export external method manifest', null, null),
            'PHP_COMPILER_INCLUDE_SCOPE_REMAP' => $bool(self::SCOPE_COMPILE, 'Remap include scopes', null, null),
            'PHP_COMPILER_COMMENT_SCAN_LEGACY' => $bool(self::SCOPE_COMPILE, 'Legacy comment scan', null, null),
            'PHP_COMPILER_FUNCTION_BODY_SCAN_LEGACY' => $bool(self::SCOPE_COMPILE, 'Legacy function body scan', null, null),
            'PHP_COMPILER_PRODUCER_INDEX_LEGACY' => $bool(self::SCOPE_COMPILE, 'Legacy producer index', null, null),
            'PHP_COMPILER_CLI_SPINE_BUNDLE' => $str(self::SCOPE_COMPILE, 'CLI spine bundle path', null, null),
            'PHP_COMPILER_LIB_SPINE_BUNDLE' => $str(self::SCOPE_COMPILE, 'Lib spine bundle path', null, null),
            'PHP_COMPILER_VM_SPINE_SMOKE' => $bool(self::SCOPE_GATE, 'VM spine smoke mode', null, null),
            'PHP_COMPILER_DEV_IMAGE' => $str(self::SCOPE_GATE, 'Dev image name override', null, null),
            'PHP_COMPILER_M3_SOURCE' => $str(self::SCOPE_COMPILE, 'M3 source override', null, null),
            'PHP_COMPILER_M3_COMPILE_DRIVER' => $bool(self::SCOPE_COMPILE, 'M3 compile driver mode', null, null),
            'PHP_COMPILER_M3_COMPILE_DRIVER_MAIN' => $bool(self::SCOPE_COMPILE, 'M3 compile driver main', null, null),
            'PHP_COMPILER_M3_EMIT_TU' => $bool(self::SCOPE_COMPILE, 'M3 emit translation unit', null, null),
            'PHP_COMPILER_M3_EMIT_HELPER_SPINE' => $bool(self::SCOPE_COMPILE, 'M3 emit helper spine', null, null),
            'PHP_COMPILER_M3_EMIT_LOG_PREFIX' => $str(self::SCOPE_DEBUG, 'M3 emit log prefix', null, null),
            'PHP_COMPILER_M3_EMIT_SIDECAR_DEPTH' => $int(self::SCOPE_COMPILE, 'M3 sidecar depth', null, null),
            'PHP_COMPILER_M3_EMIT_SIDECAR_MAX_DEPTH' => $int(self::SCOPE_COMPILE, 'M3 sidecar max depth', null, null),
            'PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD' => $bool(self::SCOPE_COMPILE, 'M3 sidecar recursion guard', null, null),
            'PHP_COMPILER_M3_INVENTORY_EMIT' => $bool(self::SCOPE_COMPILE, 'M3 inventory emit', null, null),
            'PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER' => $bool(self::SCOPE_COMPILE, 'M3 inventory emit driver', null, null),
            'PHP_COMPILER_M3_INVENTORY_NO_EMIT_HELPER_SIDECAR' => $bool(self::SCOPE_COMPILE, 'Skip helper sidecar on inventory emit', null, null),
            'PHP_COMPILER_M3_SIDECAR_HOST' => $bool(self::SCOPE_COMPILE, 'M3 sidecar host mode', null, null),
            'PHP_COMPILER_M3_INVENTORY_MINIMAL_SIDECARS' => $bool(self::SCOPE_COMPILE, 'Minimal inventory sidecars', '1', '#1492'),
            'PHP_COMPILER_M3_REUSE_STALE_COMPILER_LIB_SIDECAR' => $bool(self::SCOPE_COMPILE, 'Reuse stale compiler-lib sidecar', '1', null),
            'PHP_COMPILER_M4_BIN_COMPILE_DRIVER' => $bool(self::SCOPE_COMPILE, 'M4 bin compile driver', null, null),
            'PHP_COMPILER_M5_DRIVER_HOST' => $bool(self::SCOPE_COMPILE, 'M5 driver host mode', null, null),
            'PHP_COMPILER_M5_FORCE_PARSER_NESTEDJIT' => $bool(self::SCOPE_COMPILE, 'Force parser NestedJIT', null, null),
            'PHP_COMPILER_M5_FORCE_PARSER_NESTEDJIT_CALL' => $bool(self::SCOPE_COMPILE, 'Force parser NestedJIT call', null, null),
            'PHP_COMPILER_M5_TRIVIAL_ECHO_NESTEDJIT' => $bool(self::SCOPE_COMPILE, 'Trivial echo NestedJIT', null, null),
            'PHPCFG_SIMPLIFIER_USECHAIN' => $bool(self::SCOPE_COMPILE, 'php-cfg Simplifier use-chain (legacy opt-in)', null, '#23070'),
            'PHPCFG_SIMPLIFIER_LEGACY' => $bool(self::SCOPE_COMPILE, 'php-cfg Simplifier legacy walk opt-out', null, '#23070'),
            'PHPTYPES_RESOLVER_WORKLIST' => $bool(self::SCOPE_COMPILE, 'php-types resolver worklist', null, '#36225'),
        ];
    }
}
