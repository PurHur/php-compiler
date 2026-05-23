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
        'compilearraydimfetchread',
        'compilebooltemporary',
        'compileboolconstant',
        'compiletypeconstrainedvariable',
        'compileclassconstfetch',
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
    ];

    /** @var list<string> */
    private const WEB_BOOTSTRAP_SKIP_PATTERNS = [
        'includepathresolver',
        'literalincludediscovery',
    ];

    /** @var list<string> */
    private const SELFHOST_ENTRY_SUFFIXES = [
        '\\runtime::compilefunc',
        '\\runtime::compile',
        '\\compiler::compilefunc',
        '\\compiler::compile',
    ];

    public function testCompilerSkipPatternCount(): void
    {
        $this->assertSame(self::COMPILER_SKIP_PATTERNS, $this->compilerSkipPatternsFromJit());
        $this->assertCount(39, self::COMPILER_SKIP_PATTERNS);
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
        putenv('PHP_COMPILER_JIT_PROGRESS_FILE=/tmp/jit-selfhost-stub-test');
        try {
            $this->assertTrue(
                $this->invokeSkipCheck('isSkippedWebBootstrapHotPathName', $sample),
                "Expected isSkippedWebBootstrapHotPathName for pattern {$pattern}"
            );
        } finally {
            putenv('PHP_COMPILER_JIT_PROGRESS_FILE');
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function webBootstrapSkipPatternProvider(): iterable
    {
        foreach (self::WEB_BOOTSTRAP_SKIP_PATTERNS as $pattern) {
            yield $pattern => [$pattern, 'PHPCompiler\\Web\\'.$pattern];
        }
    }

    /**
     * @dataProvider selfHostEntrySuffixProvider
     */
    public function testIsSkippedSelfHostEntryNameMatches(string $suffix): void
    {
        putenv('PHP_COMPILER_JIT_PROGRESS_FILE=/tmp/jit-selfhost-stub-test');
        try {
            $this->assertTrue(
                $this->invokeSkipCheck('isSkippedSelfHostEntryName', 'PHPCompiler'.$suffix),
                "Expected isSkippedSelfHostEntryName for suffix {$suffix}"
            );
        } finally {
            putenv('PHP_COMPILER_JIT_PROGRESS_FILE');
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
