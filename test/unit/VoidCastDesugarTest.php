<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\VoidCastDesugar;
use PHPCompiler\CompilerVersion;
use PHPUnit\Framework\TestCase;

/** @covers issue #28183 #7346 #23037 */
final class VoidCastDesugarTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
    }

    public function testSupportsVoidCastOffForAllProfiles(): void
    {
        foreach (['8.2', '8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $this->assertFalse(
                CompilerVersion::supportsVoidCast(),
                "PROFILE={$profile} must reject (void) like Zend 8.5.8 (#28183)"
            );
        }
    }

    public function testProfile85LeavesVoidCastUntouched(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $code = '<?php (void) f();';
        $this->assertSame($code, VoidCastDesugar::desugar($code));
    }

    public function testProfile82LeavesVoidCastUntouched(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        $code = '<?php (void) f();';
        $this->assertSame($code, VoidCastDesugar::desugar($code));
    }

    public function testReturnTypeVoidUnchanged(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $code = '<?php function f(): void {}';
        $this->assertSame($code, VoidCastDesugar::desugar($code));
    }
}
