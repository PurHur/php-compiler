<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * GETTING-STARTED presenter sections stay in sync with shipped examples (#2158).
 */
final class GettingStartedDocTest extends TestCase
{
    private static string $doc;

    public static function setUpBeforeClass(): void
    {
        $path = dirname(__DIR__, 2).'/docs/GETTING-STARTED.md';
        self::$doc = (string) file_get_contents($path);
    }

    public function testThrowsWebSectionHasPresenterCurls(): void
    {
        $this->assertStringContainsString('### 5b. (Optional) ThrowsWeb', self::$doc);
        $this->assertStringContainsString('examples/007-ThrowsWeb', self::$doc);
        $this->assertStringContainsString(
            "./phpc serve 127.0.0.1:8080 examples/007-ThrowsWeb",
            self::$doc
        );
        $this->assertStringContainsString(
            "curl -sf -X POST -d 'email=bad' http://127.0.0.1:8080/example.php | grep -i invalid",
            self::$doc
        );
        $this->assertStringContainsString('make examples-throws-smoke', self::$doc);
        $this->assertStringContainsString('#2157', self::$doc);
    }
}
