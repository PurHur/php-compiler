<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: `$d = $d->modify(...)` must not store NULL (#33929).
 */
final class Issue33929DateTimeReassignAotTest extends TestCase
{
    public function testAssignObjectFromValueUsesSharedSlot(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString(
            'One shared value-box for both IR arms',
            $source,
            'OBJECT←VALUE assign must document the shared-slot fix (#33929)'
        );
        $this->assertStringContainsString('#33929', $source);
        // Regression: a second alloc after positionAtEnd(handleBlock) reintroduced NULL locals.
        $this->assertDoesNotMatchRegularExpression(
            '/positionAtEnd\(\$handleBlock\);\s*\$result->free\(\);\s*\$slot = JIT\\\\JitValueBox::alloc/s',
            $source,
            'handle arm must not allocate a second value-box for $result (#33929)'
        );
    }

    public function testReproFixtureExists(): void
    {
        $path = dirname(__DIR__, 2).'/test/repro/issue_33929_datetime_reassign_aot.php';
        $this->assertFileExists($path);
        $this->assertStringContainsString('modify', (string) file_get_contents($path));
    }
}
