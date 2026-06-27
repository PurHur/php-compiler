<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\PropertyHookProfileSkipTrait;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Static property hooks on self-backed uninitialized typed slots (#9683). */
final class StaticPropertyHookUninitTest extends TestCase
{
    use PropertyHookProfileSkipTrait;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooks();
    }

    public function testVmSelfBackedStaticHookGetSet(): void
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
C::$n = 5;
echo C::$n, "\n";
PHP;
        $path = sys_get_temp_dir().'/static_property_hook_uninit_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, $src);
        try {
            $rt = new Runtime();
            ob_start();
            $rt->run($rt->parseAndCompile($src, $path));
            self::assertSame("0\n5\n", ob_get_clean());
        } finally {
            @unlink($path);
        }
    }
}
