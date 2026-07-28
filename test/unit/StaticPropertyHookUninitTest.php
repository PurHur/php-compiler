<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** Static property hooks rejected on PROFILE=8.4 (#24281). */
final class StaticPropertyHookUninitTest extends TestCase
{
    use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }

    public function testVmRejectsSelfBackedStaticHook(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public static int $n {
        get => self::$n ?? 0;
        set (int $v) { self::$n = $v; }
    }
}
echo C::$n, "\n";
PHP;
        $path = sys_get_temp_dir().'/static_property_hook_uninit_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, $src);
        try {
            $rt = new Runtime();
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(PropertyHooks::STATIC_HOOK_COMPILE_ERROR);
            $rt->parseAndCompile($src, $path);
        } finally {
            @unlink($path);
        }
    }
}
