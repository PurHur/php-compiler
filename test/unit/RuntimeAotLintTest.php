<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT compile gate for lib units using self::CLASS_CONST (issue #84).
 */
final class RuntimeAotLintTest extends TestCase
{
    /**
     * @dataProvider libFilesUsingClassConstFetch
     */
    public function testLibFileParseAndCompile(string $relativePath): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/'.$relativePath;
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }

    public function testBootstrapClassConstFetchFixture(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/bootstrap-aot/class_const_fetch.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function libFilesUsingClassConstFetch(): array
    {
        return [
            'Runtime.php' => ['lib/Runtime.php'],
            'VM.php' => ['lib/VM.php'],
        ];
    }
}
