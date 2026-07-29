<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../BaseTest.php';

/**
 * --SKIPIF-- must be evaluated (php-src run-tests.php semantics) — #24888.
 */
final class PhptSkipIfTest extends TestCase
{
    public function testMissingSkipIfRuns(): void
    {
        $reason = BaseTest::evaluatePhptSkipIf(
            ['FILE' => "<?php\n"],
            [],
            [PHP_BINARY],
            dirname(__DIR__, 2)
        );
        $this->assertNull($reason);
    }

    public function testSkipPrefixSkips(): void
    {
        $reason = BaseTest::evaluatePhptSkipIf(
            [
                'SKIPIF' => "<?php\necho 'skip no database';\n",
                '__phpt_dir' => sys_get_temp_dir(),
            ],
            [],
            [PHP_BINARY],
            dirname(__DIR__, 2)
        );
        $this->assertNotNull($reason);
        $this->assertSame('skip no database', $reason);
    }

    public function testSilentSkipIfRuns(): void
    {
        $reason = BaseTest::evaluatePhptSkipIf(
            [
                'SKIPIF' => "<?php\n// ready\n",
                '__phpt_dir' => sys_get_temp_dir(),
            ],
            [],
            [PHP_BINARY],
            dirname(__DIR__, 2)
        );
        $this->assertNull($reason);
    }

    public function testEnvGateHonorsPhpCompilerProfile(): void
    {
        $sections = [
            'SKIPIF' => <<<'PHP'
<?php
if (!getenv('PHP_COMPILER_PROFILE') || '8.4' !== getenv('PHP_COMPILER_PROFILE')) {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
PHP,
            '__phpt_dir' => sys_get_temp_dir(),
        ];
        $repo = dirname(__DIR__, 2);
        $php = [PHP_BINARY];

        $without = BaseTest::evaluatePhptSkipIf($sections, ['PHP_COMPILER_PROFILE' => ''], $php, $repo);
        $this->assertNotNull($without);
        $this->assertStringContainsString('skip requires PHP_COMPILER_PROFILE=8.4', (string) $without);

        $with = BaseTest::evaluatePhptSkipIf($sections, ['PHP_COMPILER_PROFILE' => '8.4'], $php, $repo);
        $this->assertNull($with);
    }

    public function testLazyGhostFixtureSkipsOnPhp82Host(): void
    {
        if (PHP_VERSION_ID >= 80400) {
            $this->markTestSkipped('host is PHP 8.4+; fixture SKIPIF would not skip');
        }
        $path = dirname(__DIR__) . '/compliance/cases/language/lazy_ghost_basic.phpt';
        $this->assertFileExists($path);
        $parsed = $this->parseSections($path);
        $reason = BaseTest::evaluatePhptSkipIf(
            $parsed,
            [],
            [PHP_BINARY],
            dirname(__DIR__, 2)
        );
        $this->assertNotNull($reason);
        $this->assertStringContainsString('skip ReflectionClass::newLazyGhost requires PHP 8.4+', (string) $reason);
    }

    /**
     * @return array<string, string>
     */
    private function parseSections(string $filename): array
    {
        $sections = [];
        $section = '';
        foreach (file($filename) as $line) {
            if (preg_match('(^--([_A-Z]+)--)', $line, $result)) {
                $section = $result[1];
                $sections[$section] = '';
                continue;
            }
            $sections[$section] .= $line;
        }
        $sections['__phpt_dir'] = dirname($filename);

        return $sections;
    }
}
