<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — Slim CallableResolver patch: Closure resolveRoute short-circuit.
 */
final class SlimCallableResolverPatch36382Test extends TestCase
{
    public function testPatchIsIdempotentAndShortCircuitsClosure(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/CallableResolver_36382_'.getmypid().'.php';
        file_put_contents($tmp, <<<'PHP'
<?php
namespace Slim;
final class CallableResolver
{
    public function resolveRoute($toResolve): callable
    {
        return $this->resolveByPredicate($toResolve, [$this, 'isRoute'], 'handle');
    }
}
PHP);
        $script = $root.'/script/composer/patch-slim-callable-resolver-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('instanceof \\Closure', $patched);
        $this->assertStringContainsString('AOT (#36382): Closure resolveRoute short-circuit', $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
    }
}
