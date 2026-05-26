<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** @group aot-lint */
final class JitCompilerSelfHostStubTest extends TestCase
{
    /** @var list<string> */
    public const COMPILER_SKIP_PATTERNS = [
        'splitcfgblockafterstringkeyedarray',
        'compilecfgbranch',
        'compilecfgblock',
        'compileblock',
        'compileops',
        'compileclasslike',
        'compileclassbody',
        'compilefunction',
        'compileglobalconst',
        'compilestmt',
        'compileop',
        'compileswitchasjumpifchain',
        'compileexpr',
        'getopcodetype',
        'compileissetmulti',
        'compileisset',
        'compilecoalesce',
        'compilenullsafe',
        'compileincludeop',
        'compileparam',
        'compileterminal',
        'compilefunccall',
        'tryfoldvariablefunctionname',
        'compilecallargsends',
        'callargunpack',
        'compilearrayliteral',
        'compilearraydimfetchread',
        'compilebooltemporary',
        'compileboolconstant',
        'compiletypeconstrainedvariable',
        'compileclassconstfetch',
        'compilefirstclasscallable',
        'compilefirstclassfunctionnameslot',
        'compilefirstclassstaticnameslot',
        'compileinstanceof',
        'trycompiledefineasglobalconst',
        'markcallerlocalsusedbyliteralinclude',
        'requireoperandslot',
        'resolvesimplevariablename',
        'unwrap',
        'needscfg',
        'inheritfuncfromparent',
        'isarraydim',
        'findcoalesce',
        'resolvecoalesce',
        'resolveisset',
        'isredundantcoalescetailassign',
    ];

    /** @var list<string> */
    private const M3_COMPILER_NATIVE_LOWERING_SUFFIXES = [
        'operandschainequal',
        'unwrapoperandchain',
    ];

    /** @var list<string> */
    private const WEB_BOOTSTRAP_SKIP_PATTERNS = [
        'deployroot',
        'sourcebundler',
    ];

    /** @var list<string> */
    private const WEB_BOOTSTRAP_STUBBED_LITERALINCLUDEDISCOVERY_METHODS = [
        'discoverdirectabsolutepaths',
        'discoverabsolutepaths',
        'pathsfrommainscopeforbundle',
        'pathsfromscript',
        'walkcfgblock',
        'walkcfgblockforbundle',
        'isbundlescopeboundary',
        'walkcfgblockinternal',
    ];

    /** @var list<string> */
    private const WEB_BOOTSTRAP_STUBBED_SUPERGLOBALS_METHODS = [
        'populatefromenvironment',
        'readrequestbody',
    ];

    /** @var list<string> */
    private const WEB_BOOTSTRAP_STUBBED_CONSTSTRINGFOLDER_METHODS = [
        'foldconcat',
        'foldforinclude',
        'tryparsedeployinclude',
    ];

    /** @var list<string> */
    private const SELFHOST_ENTRY_SUFFIXES = [
        '\\runtime::compilefunc',
        '\\runtime::compile',
        '\\compiler::compilefunc',
        '\\compiler::compile',
    ];

    /** @var list<string> */
    private const CONSTSTRINGFOLDER_SHORT_HOT_PATH_SAMPLES = [
        'magicscriptconstvalue',
        'foldconcat',
        'literalstringvalue',
    ];

    /** @var list<string> */
    private const LIB_SPINE_SMOKE_STUBBED_SAMPLES = [
        'PHPCompiler\\Doctor::run',
        'PHPCompiler\\Cli\\InvokeCwd::baseDir',
        'PHPCompiler\\Web\\CgiDriver::serve',
        'PHPCompiler\\ext\\standard\\JitAddslashes::escape',
    ];

    public function testCompilerSkipPatternCount(): void
    {
        $parsed = $this->compilerSkipPatternsFromJit();
        $this->assertCount(47, self::COMPILER_SKIP_PATTERNS);
        foreach (self::COMPILER_SKIP_PATTERNS as $pattern) {
            $this->assertContains($pattern, $parsed, "Missing skip pattern {$pattern} in isSkippedCompilerHotPathName");
        }
    }

    /**
     * @dataProvider m3CompilerNativeLoweringSuffixProvider
     */
    public function testM3CompileDriverCompilerNativeLoweringIsNotStubbed(string $suffix): void
    {
        $prevSelfHost = getenv('PHP_COMPILER_SELFHOST_AOT');
        $prevM3 = getenv('PHP_COMPILER_M3_COMPILE_DRIVER');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        putenv('PHP_COMPILER_M3_COMPILE_DRIVER=1');
        try {
            $this->assertFalse(
                $this->invokeSkipCheck(
                    'isSkippedCompilerHotPathName',
                    'phpcompiler\\compiler::'.$suffix
                ),
                "Expected real lowering for Compiler::{$suffix} when M3 compile driver is on"
            );
        } finally {
            if (false === $prevSelfHost) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHost);
            }
            if (false === $prevM3) {
                putenv('PHP_COMPILER_M3_COMPILE_DRIVER');
            } else {
                putenv('PHP_COMPILER_M3_COMPILE_DRIVER='.$prevM3);
            }
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function m3CompilerNativeLoweringSuffixProvider(): iterable
    {
        foreach (self::M3_COMPILER_NATIVE_LOWERING_SUFFIXES as $suffix) {
            yield $suffix => [$suffix];
        }
    }

    /**
     * @dataProvider m3CompilerNativeLoweringSuffixProvider
     */
    public function testCompilerOperandChainHelpersRemainStubbedWithoutM3Driver(string $suffix): void
    {
        $prevSelfHost = getenv('PHP_COMPILER_SELFHOST_AOT');
        $prevM3 = getenv('PHP_COMPILER_M3_COMPILE_DRIVER');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        putenv('PHP_COMPILER_M3_COMPILE_DRIVER=0');
        try {
            $this->assertTrue(
                $this->invokeSkipCheck(
                    'isSkippedCompilerHotPathName',
                    'phpcompiler\\compiler::'.$suffix
                ),
                "Expected stub for Compiler::{$suffix} without M3 compile driver"
            );
        } finally {
            if (false === $prevSelfHost) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHost);
            }
            if (false === $prevM3) {
                putenv('PHP_COMPILER_M3_COMPILE_DRIVER');
            } else {
                putenv('PHP_COMPILER_M3_COMPILE_DRIVER='.$prevM3);
            }
        }
    }

    /**
     * @dataProvider compilerSkipPatternProvider
     */
    public function testIsSkippedCompilerHotPathNameMatches(string $pattern, string $sample): void
    {
        $this->assertTrue(
            $this->invokeSkipCheck('isSkippedCompilerHotPathName', $sample),
            "Expected isSkippedCompilerHotPathName for pattern {$pattern}"
        );
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function compilerSkipPatternProvider(): iterable
    {
        $samples = [
            'resolveisset' => 'PHPCompiler\\Compiler::resolveIssetTarget',
            'markcallerlocalsusedbyliteralinclude' => 'PHPCompiler\\Compiler::markCallerLocalsUsedByLiteralInclude',
        ];
        foreach (self::COMPILER_SKIP_PATTERNS as $pattern) {
            yield $pattern => [
                $pattern,
                $samples[$pattern] ?? 'PHPCompiler\\Compiler::'.$pattern,
            ];
        }
    }

    public function testIncludePathResolverResolveIsNotCompilerStub(): void
    {
        $this->assertFalse(
            $this->invokeSkipCheck(
                'isSkippedCompilerHotPathName',
                'phpcompiler\\web\\includepathresolver::resolve'
            )
        );
    }

    /**
     * @dataProvider webBootstrapSkipPatternProvider
     */
    public function testIsSkippedWebBootstrapHotPathNameMatches(string $pattern, string $sample): void
    {
        $prev = getenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        try {
            $this->assertTrue(
                $this->invokeSkipCheck('isSkippedWebBootstrapHotPathName', $sample),
                "Expected isSkippedWebBootstrapHotPathName for pattern {$pattern}"
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prev);
            }
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function webBootstrapSkipPatternProvider(): iterable
    {
        foreach (self::WEB_BOOTSTRAP_SKIP_PATTERNS as $pattern) {
            yield $pattern => [$pattern, 'PHPCompiler\\Web\\'.$pattern];
        }
        foreach (self::WEB_BOOTSTRAP_STUBBED_SUPERGLOBALS_METHODS as $method) {
            yield 'superglobals::'.$method => [
                'superglobals::'.$method,
                'PHPCompiler\\Web\\Superglobals::'.$method,
            ];
        }
        foreach (self::WEB_BOOTSTRAP_STUBBED_CONSTSTRINGFOLDER_METHODS as $method) {
            yield 'conststringfolder::'.$method => [
                'conststringfolder::'.$method,
                'PHPCompiler\\Web\\ConstStringFolder::'.$method,
            ];
        }
        foreach (self::WEB_BOOTSTRAP_STUBBED_LITERALINCLUDEDISCOVERY_METHODS as $method) {
            yield 'literalincludediscovery::'.$method => [
                'literalincludediscovery::'.$method,
                'PHPCompiler\\Web\\LiteralIncludeDiscovery::'.$method,
            ];
        }
    }

    public function testIncludePathResolverResolveIsWebBootstrapStub(): void
    {
        $prev = getenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        try {
            $this->assertTrue(
                $this->invokeSkipCheck(
                    'isSkippedWebBootstrapHotPathName',
                    'phpcompiler\\web\\includepathresolver::resolve'
                )
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prev);
            }
        }
    }

    public function testConstStringFolderLiteralStringValueIsWebBootstrapStub(): void
    {
        $prev = getenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        try {
            $this->assertTrue(
                $this->invokeSkipCheck(
                    'isSkippedWebBootstrapHotPathName',
                    'phpcompiler\\web\\conststringfolder::literalstringvalue'
                )
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prev);
            }
        }
    }

    public function testConstStringFolderSourceDirIsWebBootstrapStub(): void
    {
        $prev = getenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        try {
            $this->assertTrue(
                $this->invokeSkipCheck(
                    'isSkippedWebBootstrapHotPathName',
                    'phpcompiler\\web\\conststringfolder::sourcedir'
                )
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prev);
            }
        }
    }

    public function testIsSuperglobalNameIsNotWebBootstrapStub(): void
    {
        $prev = getenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        try {
            $this->assertFalse(
                $this->invokeSkipCheck(
                    'isSkippedWebBootstrapHotPathName',
                    'phpcompiler\\web\\superglobals::issuperglobalname'
                )
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prev);
            }
        }
    }

    public function testIssetHelperCompileRemainsSelfHostStub(): void
    {
        $prev = getenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        try {
            $this->assertTrue(
                $this->invokeSkipCheck(
                    'isSkippedIssetHelperHotPathName',
                    'phpcompiler\\jit\\issethelper::compile'
                )
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prev);
            }
        }
    }

    /**
     * @dataProvider selfHostEntrySuffixProvider
     */
    public function testIsSkippedSelfHostEntryNameMatches(string $suffix): void
    {
        $prev = getenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        try {
            $this->assertTrue(
                $this->invokeSkipCheck('isSkippedSelfHostEntryName', 'PHPCompiler'.$suffix),
                "Expected isSkippedSelfHostEntryName for suffix {$suffix}"
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prev);
            }
        }
    }

    /**
     * @dataProvider constStringFolderShortHotPathProvider
     */
    public function testIsSkippedConstStringFolderShortHotPathNameMatches(string $sample): void
    {
        $prev = getenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        try {
            $this->assertTrue(
                $this->invokeSkipCheck(
                    'isSkippedWebBootstrapHotPathName',
                    'phpcompiler\\web\\conststringfolder::'.$sample
                ),
                "Expected isSkippedWebBootstrapHotPathName for ConstStringFolder::{$sample}"
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prev);
            }
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function constStringFolderShortHotPathProvider(): iterable
    {
        foreach (self::CONSTSTRINGFOLDER_SHORT_HOT_PATH_SAMPLES as $sample) {
            yield $sample => [$sample];
        }
    }

    /**
     * @dataProvider libSpineSmokeStubSampleProvider
     */
    public function testIsSkippedLibSpineSmokeHotPathNameMatches(string $sample): void
    {
        $prev = getenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        try {
            $this->assertTrue(
                $this->invokeSkipCheck('isSkippedLibSpineSmokeHotPathName', $sample),
                "Expected isSkippedLibSpineSmokeHotPathName for {$sample}"
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prev);
            }
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function libSpineSmokeStubSampleProvider(): iterable
    {
        foreach (self::LIB_SPINE_SMOKE_STUBBED_SAMPLES as $sample) {
            yield $sample => [$sample];
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function selfHostEntrySuffixProvider(): iterable
    {
        foreach (self::SELFHOST_ENTRY_SUFFIXES as $suffix) {
            yield $suffix => [$suffix];
        }
    }

    public function testCompileBoolTemporaryPatternIsListed(): void
    {
        $this->assertContains('compilebooltemporary', self::COMPILER_SKIP_PATTERNS);
        $this->assertTrue(
            $this->invokeSkipCheck(
                'isSkippedCompilerHotPathName',
                'phpcompiler\\compiler::compilebooltemporary'
            )
        );
    }

    public function testSelfHostStubsTolerateDuplicateGlobalConstDeclare(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString(
            'Spine may require bin/vm.php after tokenizer-compat shims (#2134)',
            $source
        );
        $this->assertMatchesRegularExpression(
            '/defineConstant\([^)]+\)\)\) \{\s*\/\/ Spine may require bin\/vm\.php/s',
            $source
        );
    }

    /** @return list<string> */
    private function compilerSkipPatternsFromJit(): array
    {
        $ref = new ReflectionMethod(JIT::class, 'isSkippedCompilerHotPathName');
        $ref->setAccessible(true);
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        if (!preg_match(
            '/function isSkippedCompilerHotPathName\(string \$name\): bool\s*\{.*?return (.*?);\s*\n    \}/s',
            $source,
            $matches
        )) {
            $this->fail('Unable to parse isSkippedCompilerHotPathName from lib/JIT.php');
        }
        preg_match_all("/str_contains\\(\\\$lower, '([^']+)'\\)/", $matches[1], $found);

        return $found[1];
    }

    private function invokeSkipCheck(string $method, string $name): bool
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $jit = $runtime->loadJit();
        $ref = new ReflectionMethod(JIT::class, $method);
        $ref->setAccessible(true);

        return (bool) $ref->invoke($jit, $name);
    }
}
