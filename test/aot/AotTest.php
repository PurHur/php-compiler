<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

use PHPCompiler\JIT\Builtin\OpensslEncryptRuntime;
use PHPCompiler\JIT\Builtin\OpensslSignRuntime;

/**
 * End-to-end AOT tests: compile PHP to a native binary via LLVM and run it.
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
class AotTest extends BaseTest
{
    protected static string $DIR = __DIR__ . '/../fixtures/aot';

    /**
     * CGI vars read at AOT runtime via __superglobals__refresh; unset during compile
     * so PHP/LLVM is not blocked or slowed by CONTENT_LENGTH-style environ (issue #314).
     *
     * @var list<string>
     */
    private const COMPILE_EXCLUDED_ENV = [
        'CONTENT_LENGTH',
        'CONTENT_TYPE',
        'REQUEST_BODY',
    ];

    private static ?bool $llvmReady = null;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/compile.php');
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
    }

    private static function isLlvmReady(): bool
    {
        if (null !== self::$llvmReady) {
            return self::$llvmReady;
        }
        self::$llvmReady = LlvmToolchain::isReady(dirname(__DIR__, 2));

        return self::$llvmReady;
    }

    public static function providePHPTests(): \Generator
    {
        foreach (parent::providePHPTests() as $name => $case) {
            // Functional str_increment*_forward* cases set PROFILE via --ENV--; always include (#24820).
            if (!CompilerVersion::supportsStrIncrement()
                && (str_contains($name, 'str_increment') || str_contains($name, 'str_decrement'))
                && !str_contains($name, 'str_increment_phantom')
                && !str_contains($name, 'forward')) {
                continue;
            }
            if (CompilerVersion::supportsStrIncrement()
                && str_contains($name, 'str_increment_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsGetObjectId()
                && str_contains($name, 'get_object_id')
                && !str_contains($name, 'get_object_id_phantom')
                && !str_contains($name, 'get_object_id_function_exists_forward')) {
                continue;
            }
            if (CompilerVersion::supportsGetObjectId()
                && str_contains($name, 'get_object_id_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsClamp()
                && str_contains($name, 'clamp')) {
                continue;
            }
            if (!CompilerVersion::supportsPhp85ArrayFirstLast()
                && ((str_contains($name, 'array_first') && !str_contains($name, 'array_first_key') && !str_contains($name, 'array_first_last_key'))
                    || (str_contains($name, 'array_last') && !str_contains($name, 'array_last_key') && !str_contains($name, 'array_first_last_key')))
                && !str_contains($name, 'array_first_last_phantom_forward_84')
                && !str_contains($name, 'array_first_last_forward_85')) {
                continue;
            }
            if (CompilerVersion::supportsPhp85ArrayFirstLast()
                && str_contains($name, 'array_first_last_phantom_forward_84')) {
                continue;
            }
            if (!CompilerVersion::supportsPhp84ReflectionProbeBuiltins()
                && (str_contains($name, 'attribute_exists')
                    || str_contains($name, 'class_meth_exists')
                    || str_contains($name, 'unitenum_exists'))
                && !str_contains($name, 'reflection_probe_builtins_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsIsAnonymousClass()
                && str_contains($name, 'is_anonymous_class')
                && !str_contains($name, 'is_anonymous_class_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsPhp84ReflectionProbeBuiltins()
                && str_contains($name, 'reflection_probe_builtins_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsClassHasFunctions()
                && (str_contains($name, 'class_has_method')
                    || str_contains($name, 'class_has_property')
                    || str_contains($name, 'class_has_constant')
                    || str_contains($name, 'class_has_functions'))) {
                continue;
            }
            if (CompilerVersion::supportsClassHasFunctions()
                && str_contains($name, 'class_has_functions_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionParameterIsSensitiveParameter()
                && str_contains($name, 'reflection_parameter_is_sensitive_parameter')
                && !str_contains($name, 'reflection_parameter_is_sensitive_parameter_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionParameterIsSensitiveParameter()
                && str_contains($name, 'reflection_parameter_is_sensitive_parameter_phantom')) {
                continue;
            }
            // isSensitive (#22899 / #7072) — same 8.4 gate; exclude *_parameter* names.
            if (!CompilerVersion::supportsReflectionParameterIsSensitiveParameter()
                && str_contains($name, 'reflection_parameter_is_sensitive')
                && !str_contains($name, 'reflection_parameter_is_sensitive_parameter')
                && !str_contains($name, 'reflection_parameter_is_sensitive_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionParameterIsSensitiveParameter()
                && str_contains($name, 'reflection_parameter_is_sensitive_phantom')
                && !str_contains($name, 'reflection_parameter_is_sensitive_parameter_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsEncapsedCoalesce()
                && str_contains($name, 'encapsed_coalesce_interpolation')) {
                continue;
            }
            if (!CompilerVersion::supportsHex2binStrict()
                && str_contains($name, 'hex2bin_strict')
                && !str_contains($name, 'hex2bin_strict_arity_reference_profile')
                && !str_contains($name, 'hex2bin_strict_named_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsHex2binStrict()
                && (str_contains($name, 'hex2bin_strict_arity_reference_profile')
                    || str_contains($name, 'hex2bin_strict_named_reference_profile'))) {
                continue;
            }
            if (!CompilerVersion::supportsFpow()
                && (str_contains($name, 'fpow') || str_contains($name, 'fmin') || str_contains($name, 'fmax')
                    || str_contains($name, 'fadd') || str_contains($name, 'fsub') || str_contains($name, 'fmul'))) {
                continue;
            }
            if (!CompilerVersion::supportsNextafter()
                && str_contains($name, 'nextafter')) {
                continue;
            }
            if (!CompilerVersion::supportsRoundingModeEnum()
                && (str_contains($name, 'rounding_mode') || str_contains($name, 'bcround'))
                && !str_contains($name, 'rounding_mode_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsRoundingModeEnum()
                && str_contains($name, 'rounding_mode_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsRoundingModeEnum()
                && str_contains($name, 'round_invalid_mode_84')) {
                continue;
            }
            if (CompilerVersion::supportsRoundingModeEnum()
                && 'round_invalid_mode.phpt' === $name) {
                continue;
            }
            if (!CompilerVersion::supportsNumberFormatNegativeDecimals()
                && str_contains($name, 'number_format_negative_decimals')
                && !str_contains($name, 'number_format_negative_decimals_84')) {
                continue;
            }
            if (CompilerVersion::supportsNumberFormatNegativeDecimals()
                && 'number_format_negative_decimals.phpt' === $name) {
                continue;
            }
            // SprintfJitHelper user-script AOT: helper cache runtime_safe:false or nested
            // compile OOM (#15642, #16075) — VM/JIT compliance covers parity (#18525).
            // #27899 negative-decimals_84 is verified via bin/compile.php repro (not this suite).
            if (str_contains($name, 'number_format')
                && !str_contains($name, 'number_format_negative_decimals_84')) {
                continue;
            }
            if (!CompilerVersion::supportsRandomIntervalBoundary()
                && str_contains($name, 'random_interval_boundary')
                && !str_contains($name, 'random_interval_boundary_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsRandomIntervalBoundary()
                && str_contains($name, 'random_interval_boundary_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsGetDeclaredExcludeDeprecated()
                && str_contains($name, 'get_declared_exclude_deprecated')
                && !str_contains($name, 'get_declared_exclude_deprecated_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsGetDeclaredExcludeDeprecated()
                && str_contains($name, 'get_declared_exclude_deprecated_reference_profile')) {
                continue;
            }
            // get_class $allow_string gate retired (#28310) — both allow_string*.phpt cases always run.
            if (!CompilerVersion::supportsGetDefinedFunctionsExcludeDisabled()
                && str_contains($name, 'get_defined_functions_exclude_disabled')
                && !str_contains($name, 'get_defined_functions_exclude_disabled_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsGetDefinedFunctionsExcludeDisabled()
                && str_contains($name, 'get_defined_functions_exclude_disabled_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsZendThreadId() && str_contains($name, 'zend_thread_id')) {
                continue;
            }
            if (!CompilerVersion::supportsGetmygrgid() && str_contains($name, 'getmygrgid')) {
                continue;
            }
            if (!CompilerVersion::supportsCrc32c() && str_contains($name, 'crc32c')) {
                continue;
            }
            // AOT mb_str_pad_*_forward* / json_validate_*_forward* fixtures set PROFILE via --ENV--; always include (#22373, #22544).
            if (!CompilerVersion::supportsMbStrPad()
                && str_contains($name, 'mb_str_pad')
                && !str_contains($name, 'forward')) {
                continue;
            }
            if (!CompilerVersion::supportsJsonValidate()
                && str_contains($name, 'json_validate')
                && !str_contains($name, 'forward')) {
                continue;
            }
            if (!CompilerVersion::supportsGetHandlerIntrospection()
                && (str_contains($name, 'get_error_handler') || str_contains($name, 'get_exception_handler'))
                && !str_contains($name, 'get_error_handler_phantom')
                && !str_contains($name, 'get_error_handler_forward_85')
                && !str_contains($name, 'get_error_handler_forward85')) {
                continue;
            }
            if (CompilerVersion::supportsGetHandlerIntrospection()
                && str_contains($name, 'get_error_handler_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsStreamSupports()
                && (str_contains($name, 'stream_supports') || str_contains($name, 'stream_meta_seekable'))
                && !str_contains($name, 'stream_supports_lock')
                && !str_contains($name, 'stream_supports_undefined_function_before_const')) {
                continue;
            }
            // STREAM_SUPPORT_READ/WRITE PHP 8.4 constants; forward profile only (#16846).
            if (!CompilerVersion::supportsStreamSupportReadWriteConstants()
                && str_contains($name, 'stream_support_read_write_constants')) {
                continue;
            }
            // convert_cyr_string / money_format removed in php-src 8.0 (#21481): functional AOT uses
            // PROFILE=7.4 via --ENV--; phantom_* cases always include.
            if (!CompilerVersion::supportsStrxfrm()
                && str_contains($name, 'strxfrm')) {
                continue;
            }
            if (\PHPCompiler\ext\intl\IntlExtensionPolicy::advertisesBuiltins()
                && (str_contains($name, 'grapheme_phantom')
                    || str_contains($name, 'grapheme_stripos_intl_gated')
                    || str_contains($name, 'grapheme_forward_profile')
                    || str_contains($name, 'grapheme_profile_84')
                    || str_contains($name, 'idn_phantom')
                    || str_contains($name, 'normalizer_phantom')
                    || str_contains($name, 'intl_phantom')
                    || str_contains($name, 'intl_phantom_classes')
                    || str_contains($name, 'intl_skeleton_stub')
                    || str_contains($name, 'locale_gated'))) {
                continue;
            }
            if (!CompilerVersion::supportsGraphemeStrimwidth()
                && str_contains($name, 'grapheme_strimwidth')
                && !str_contains($name, 'grapheme_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsGraphemeStrSplit()
                && str_contains($name, 'grapheme_str_split')
                && !str_contains($name, 'grapheme_str_split_profile_82')
                && !str_contains($name, 'grapheme_str_split_function_exists_forward_84')
                && !str_contains($name, 'grapheme_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsGraphemeCompliance($name)
                && str_contains($name, 'grapheme_')
                && !str_contains($name, 'grapheme_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIdnCompliance($name)
                && (str_contains($name, 'idn_to_ascii') || str_contains($name, 'idn_to_utf8') || str_contains($name, 'idn_enum'))
                && !str_contains($name, 'idn_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsNormalizerCompliance($name)
                && str_contains($name, 'normalizer_')
                && !str_contains($name, 'normalizer_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance($name)
                && (str_contains($name, 'locale_get_default')
                    || str_contains($name, 'locale_class')
                    || str_contains($name, 'locale_set_default'))
                && !str_contains($name, 'locale_gated')
                && !str_contains($name, 'intl_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleParserCompliance($name)
                && str_contains($name, 'locale_get_parts')
                && !str_contains($name, 'locale_gated')
                && !str_contains($name, 'intl_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance($name)
                && (str_contains($name, 'intldateformatter')
                    || str_contains($name, 'numberformatter')
                    || str_contains($name, 'intlcalendar')
                    || str_contains($name, 'msgfmt_format')
                    || str_contains($name, 'transliterator')
                    || str_contains($name, 'resourcebundle')
                    || str_contains($name, 'intl_skeleton')
                    || str_contains($name, 'intl_char')
                    || str_contains($name, 'intl_uconverter')
                    || str_contains($name, 'collator_'))
                && !str_contains($name, 'intl_phantom')) {
                continue;
            }
            // curl_escape/unescape require CurlHandle + easy stubs (#20493)
            if (!\PHPCompiler\ext\curl\CurlExtensionPolicy::advertisesEasyHandleStubs()
                && str_contains($name, 'curl_escape')
                && !str_contains($name, 'curl_escape_phantom')) {
                continue;
            }
            if (\PHPCompiler\ext\curl\CurlExtensionPolicy::advertisesExtension()
                && str_contains($name, 'curl_escape_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\curl\CurlExtensionPolicy::runsCurlFileCompliance($name)
                && (str_contains($name, 'curl_file_create')
                    || str_contains($name, 'curl_string_file'))
                && !str_contains($name, 'curl_file_phantom')) {
                continue;
            }
            if (\PHPCompiler\ext\curl\CurlExtensionPolicy::advertisesFileClasses()
                && str_contains($name, 'curl_file_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\curl\CurlExtensionPolicy::runsCurlShareCompliance($name)
                && str_contains($name, 'curl_share')
                && !str_contains($name, 'curl_share_phantom')) {
                continue;
            }
            if (\PHPCompiler\ext\curl\CurlExtensionPolicy::advertisesShareHandles()
                && str_contains($name, 'curl_share_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\curl\CurlExtensionPolicy::runsCurlEasyCompliance($name)
                && (str_contains($name, 'curl_setopt_array') || str_contains($name, 'curl_opt_constants'))
                && !str_contains($name, 'curl_easy_phantom')) {
                continue;
            }
            if (\PHPCompiler\ext\curl\CurlExtensionPolicy::advertisesEasyHandleStubs()
                && str_contains($name, 'curl_easy_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\curl\CurlExtensionPolicy::runsCurlMultiCompliance($name)
                && str_contains($name, 'curl_multi')
                && !str_contains($name, 'curl_multi_strerror')
                && !str_contains($name, 'curl_multi_phantom')) {
                continue;
            }
            if (\PHPCompiler\ext\curl\CurlExtensionPolicy::advertisesMultiHandles()
                && str_contains($name, 'curl_multi_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsArrayReplaceKey()
                && str_contains($name, 'array_replace_key')
                && !str_contains($name, 'array_replace_key_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsArrayReplaceKey()
                && str_contains($name, 'array_replace_key_phantom')) {
                continue;
            }
            $usesHttpLastResponseHeaders = str_contains($name, 'http_get_last_response_headers')
                || str_contains($name, 'get_last_response_headers')
                || str_contains($name, 'http_clear_last_response_headers');
            if (!CompilerVersion::supportsHttpLastResponseHeaders()
                && $usesHttpLastResponseHeaders
                && !str_contains($name, 'http_last_response_headers_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsHttpLastResponseHeaders()
                && str_contains($name, 'http_last_response_headers_phantom')) {
                continue;
            }
            $usesHeaderList = str_contains($name, 'header_list')
                || str_contains($name, 'header_remove')
                || str_contains($name, 'setcookie')
                || str_contains($name, 'setrawcookie')
                || str_contains($name, 'session_cookie');
            if (!CompilerVersion::supportsHeaderList() && $usesHeaderList) {
                continue;
            }
            // Pipe operator AOT: enabled after AssertOptionsRuntime CFG fix (#9750).
            // Concat-on-LHS (`"a" . "b" |> f`) remains VM/JIT-only until inline concat-in-call AOT lands.
            // preg_match() float offset: VM (#13818); native AOT emits float→int deprecation on stderr before stdout.
            if (str_contains($name, 'preg_match_float_offset')) {
                continue;
            }
            if (str_contains($name, 'openssl_sign_verify')
                && (!OpensslSignRuntime::opensslEvRuntimeAvailable()
                    || !\PHPCompiler\ext\openssl\VmOpensslSignNative::available())) {
                continue;
            }
            if (str_contains($name, 'openssl_encrypt_decrypt')
                && (!OpensslEncryptRuntime::opensslCipherRuntimeAvailable()
                    || !\PHPCompiler\ext\openssl\VmOpensslCipherNative::available())) {
                continue;
            }
            if (!CompilerVersion::supportsTryCatchElse()
                && str_contains($name, 'try_catch_else')) {
                continue;
            }
            // ext/mysqli — VM host bridge; user-script AOT deferred (#3435, #21788).
            if (str_contains($name, 'mysqli')) {
                continue;
            }
            yield $name => $case;
        }
    }

    /**
     * @dataProvider providePHPTests
     */
    public function testCases(string $name, string $code, array $sections): void
    {
        $outfile = sys_get_temp_dir().'/phpc_aot_'.bin2hex(random_bytes(8));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $repoRoot = dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);
        self::applyEnvSection($env, $sections);
        PhptWebSections::applyToEnv($env, $sections);
        self::applyDefaultAotWebEnv($env, $name);
        $bodyFile = null;
        if (isset($sections['POST']) && '' !== $sections['POST']) {
            $bodyFile = tempnam(sys_get_temp_dir(), 'phpc_post_');
            if (false !== $bodyFile) {
                file_put_contents($bodyFile, $sections['POST']);
                $env['REQUEST_BODY_FILE'] = $bodyFile;
                unset($env['REQUEST_BODY']);
            }
        }
        $runEnv = $env;
        $compileEnv = $env;
        foreach (self::COMPILE_EXCLUDED_ENV as $exclude) {
            unset($compileEnv[$exclude]);
        }

        $compileArgv = [$this->BIN, '-o', $outfile];
        if (isset($sections['ENV'])) {
            foreach (explode("\n", trim($sections['ENV'])) as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (2 !== count($parts)) {
                    continue;
                }
                if ('QUERY_STRING' === $parts[0]) {
                    $compileArgv[] = '-q';
                    $compileArgv[] = $parts[1];
                }
                // REQUEST_BODY in --ENV-- is for runtime refresh only (issues #291, #314).
            }
        }
        $compileArgv = array_merge($compileArgv, PhptWebSections::compileArgvFlags($sections));
        if (null !== $bodyFile) {
            $stripped = [];
            $skipNext = false;
            foreach ($compileArgv as $arg) {
                if ($skipNext) {
                    $skipNext = false;
                    continue;
                }
                if ('-p' === $arg) {
                    $skipNext = true;
                    continue;
                }
                $stripped[] = $arg;
            }
            $compileArgv = $stripped;
        }
        $includeFile = null;
        $entryFile = null;
        $runfile = isset($sections['RUNFILE']) ? trim($sections['RUNFILE']) : '';
        if ('' !== $runfile) {
            $runPath = realpath(($sections['__phpt_dir'] ?? $repoRoot).'/'.$runfile);
            $this->assertNotFalse($runPath, "RUNFILE not found: {$runfile}");
            $compileArgv[] = $runPath;
        } elseif (isset($sections['INCLUDE'])) {
            $includeFile = tempnam(sys_get_temp_dir(), 'phpc_inc_');
            $this->assertNotFalse($includeFile);
            file_put_contents($includeFile, $sections['INCLUDE']);
            $compileArgv[] = '--include';
            $compileArgv[] = $includeFile;
            $entryFile = tempnam(sys_get_temp_dir(), 'phpc_ent_').'.php';
            $this->assertNotFalse($entryFile);
            file_put_contents($entryFile, $code);
            $compileArgv[] = $entryFile;
        } else {
            $compileArgv[] = '-';
        }

        $compile = proc_open(
            array_merge(self::llvmEnvPrefix(), $this->phpCommand(), $compileArgv),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $compileEnv
        );
        if ('' === $runfile && !isset($sections['INCLUDE'])) {
            fwrite($pipes[0], $code);
        }
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileExit = proc_close($compile);
        $compileErrText = trim($compileErr !== false ? $compileErr : '');
        $this->assertSame(
            0,
            $compileExit,
            "AOT compile failed for {$name}: {$compileErrText}"
        );
        $this->assertFileExists($outfile, $compileErrText);
        $this->assertTrue(is_executable($outfile), $compileErrText);

        $run = proc_open(
            array_merge(self::llvmEnvPrefix(), [$outfile]),
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $runPipes,
            $repoRoot,
            self::sanitizeAotRunEnv($runEnv)
        );
        $result = stream_get_contents($runPipes[1]);
        $runErr = stream_get_contents($runPipes[2]);
        if (isset($runPipes[0]) && \is_resource($runPipes[0])) {
            fclose($runPipes[0]);
        }
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $exitCode = proc_close($run);
        @unlink($outfile);
        if (isset($bodyFile)) {
            @unlink($bodyFile);
        }
        if (null !== $includeFile) {
            @unlink($includeFile);
        }
        if (null !== $entryFile) {
            @unlink($entryFile);
        }

        $runErrText = trim($runErr !== false ? $runErr : '');
        if (isset($sections['EXPECT_EXIT'])) {
            $this->assertSame(
                (int) trim($sections['EXPECT_EXIT']),
                $exitCode,
                "AOT run for {$name} stderr: {$runErrText}"
            );
        } else {
            $this->assertSame(0, $exitCode, "AOT run for {$name} stderr: {$runErrText}");
        }
        $this->assertExpect($result !== false ? $result : '', $sections);
    }

    /**
     * Standalone AOT binaries defer-flush header() before body output (#634).
     * Default GET unless a case explicitly tests CLI header_list() semantics.
     *
     * @param array<string, string> $env
     */
    private static function applyDefaultAotWebEnv(array &$env, string $caseName): void
    {
        if (isset($env['REQUEST_METHOD'])) {
            return;
        }
        if (str_contains($caseName, 'headers_list')) {
            return;
        }
        // Leave unset when a body is present so __phpc_sg_request_method_for infers POST (#878).
        if (isset($env['REQUEST_BODY']) && '' !== $env['REQUEST_BODY']) {
            return;
        }
        if (isset($env['REQUEST_BODY_FILE']) && '' !== $env['REQUEST_BODY_FILE']) {
            return;
        }
        $env['REQUEST_METHOD'] = 'GET';
    }

    /**
     * Standalone AOT execute must not inherit PHPUnit/bootstrap PHP_COMPILER_* knobs —
     * libcrypto hash bridges + superglobal refresh mis-read them and abort at exit (#19165).
     *
     * @param array<string, string> $env
     *
     * @return array<string, string>
     */
    private static function sanitizeAotRunEnv(array $env): array
    {
        $out = [];
        foreach ($env as $key => $value) {
            if (!\is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'PHP_COMPILER_') || str_starts_with($key, 'BOOTSTRAP_')) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

}
