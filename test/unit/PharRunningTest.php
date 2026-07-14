<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\phar\VmPhar;
use PHPUnit\Framework\TestCase;

/** Phar::running() path extraction — #3436. */
final class PharRunningTest extends TestCase
{
    public function testRunningPathFromPlainScript(): void
    {
        self::assertSame('', VmPhar::runningPath('/tmp/repro.php', false));
    }

    public function testRunningPathFromPharScript(): void
    {
        self::assertSame(
            '/app/tool.phar',
            VmPhar::runningPath('/app/tool.phar/internal/index.php', false)
        );
    }

    public function testRunningPathFromPharUri(): void
    {
        self::assertSame(
            '/data/app.phar',
            VmPhar::runningPath('phar:///data/app.phar/bootstrap.php', false)
        );
    }

    public function testRunningAliasWhenRetPharTrue(): void
    {
        self::assertSame('tool', VmPhar::runningPath('/app/tool.phar/index.php', true));
    }
}
