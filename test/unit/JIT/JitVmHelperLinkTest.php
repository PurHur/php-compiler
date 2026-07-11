<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\JIT\JitVmHelperLink;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** JitVmHelperLink resolves ext/* and lib/* from repo root (#1492 bootstrap inventory emit). */
final class JitVmHelperLinkTest extends TestCase
{
    public function testResolveHelperPathExtUnderRepoRoot(): void
    {
        $path = self::resolveHelperPath('/ext/standard/GetdateJitHelper.php');
        $this->assertSame(
            realpath(__DIR__.'/../../../ext/standard/GetdateJitHelper.php'),
            realpath($path)
        );
    }

    public function testResolveHelperPathLibVmUnderRepoRoot(): void
    {
        $path = self::resolveHelperPath('/lib/VM/ScalarDimFetchJitHelper.php');
        $this->assertSame(
            realpath(__DIR__.'/../../../lib/VM/ScalarDimFetchJitHelper.php'),
            realpath($path)
        );
    }

    public function testResolveHelperPathVmUnderLib(): void
    {
        $path = self::resolveHelperPath('/VM/ValueEchoJitHelper.php');
        $this->assertSame(
            realpath(__DIR__.'/../../../lib/VM/ValueEchoJitHelper.php'),
            realpath($path)
        );
    }

    private static function resolveHelperPath(string $relative): string
    {
        $method = new ReflectionMethod(JitVmHelperLink::class, 'resolveHelperPath');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $relative);
    }
}
