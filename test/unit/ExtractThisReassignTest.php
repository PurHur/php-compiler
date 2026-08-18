<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ScopeBuiltinJitHelper;
use PHPCompiler\ext\standard\VmScope;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * extract(['this'=>1]) throws Error Cannot re-assign $this (#32226).
 *
 * php-src: ext/standard/array.c php_extract / php_extract_skip (#77135).
 */
final class ExtractThisReassignTest extends TestCase
{
    public function testRejectExtractThisThrows(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot re-assign $this');
        ScopeBuiltinJitHelper::rejectExtractThis('this');
    }

    public function testRejectExtractThisIgnoresOtherNames(): void
    {
        ScopeBuiltinJitHelper::rejectExtractThis('foo');
        ScopeBuiltinJitHelper::rejectExtractThis('GLOBALS');
        $this->addToAssertionCount(1);
    }

    public function testExtrSkipNeverImportsThis(): void
    {
        $this->assertNull(
            ScopeBuiltinJitHelper::resolveExtractFinalName('this', true, VmScope::EXTR_SKIP, null)
        );
        $this->assertNull(
            ScopeBuiltinJitHelper::resolveExtractFinalName('this', false, VmScope::EXTR_SKIP, null)
        );
    }

    public function testExtrPrefixInvalidPrefixesThis(): void
    {
        $this->assertSame(
            'p_this',
            ScopeBuiltinJitHelper::resolveExtractFinalName('this', false, VmScope::EXTR_PREFIX_INVALID, 'p')
        );
    }

    public function testExtrPrefixAllPrefixesThis(): void
    {
        $this->assertSame(
            'p_this',
            ScopeBuiltinJitHelper::resolveExtractFinalName('this', true, VmScope::EXTR_PREFIX_ALL, 'p')
        );
    }

    public function testVmExtractThisInMethodThrowsAndLeavesThis(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
class C {
    public function m(): void {
        try {
            extract(['this' => 1]);
            echo "accepted\n";
        } catch (Error $e) {
            echo get_class($e), ':', $e->getMessage(), "\n";
            echo is_object($this) ? "this-ok\n" : "this-lost\n";
        }
        extract(['this' => 1], EXTR_SKIP);
        echo is_object($this) ? "skip-ok\n" : "skip-lost\n";
    }
}
(new C())->m();
PHP
            ,
            'extract_this.php'
        );
        ob_start();
        $runtime->run($block);
        $this->assertSame(
            "Error:Cannot re-assign \$this\nthis-ok\nskip-ok\n",
            ob_get_clean()
        );
    }
}
