<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VendorPrelinkClosureStubTest extends TestCase
{
    public function testVendorPrelinkAllowsClosureExpressionLint(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = sprintf(
            'PHP_COMPILER_VENDOR_PRELINK=1 %s %s/bin/compile.php -l %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root),
            escapeshellarg($root.'/test/bootstrap-aot/vendor_prelink_closure_stub.php')
        );
        exec($cmd, $out, $code);
        if (0 !== $code) {
            self::fail(implode("\n", $out));
        }
        $this->assertSame(0, $code);
    }
}

