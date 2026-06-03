<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

final class StaticPropertyHooksTest extends TestCase
{
    public function testStaticPropertyHooksRepro4751(): void
    {
        $src = <<<'PHP'
<?php
class Box {
    public static string $label {
        get => 'static:' . self::$label;
        set => strtoupper($value);
    }
}
Box::$label = 'hi';
echo Box::$label, "\n";
PHP;
        $path = sys_get_temp_dir().'/static_property_hooks_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, $src);
        try {
            $rt = new Runtime();
            ob_start();
            $rt->run($path);
            self::assertSame("static:HI\n", ob_get_clean());
        } finally {
            @unlink($path);
        }
    }
}
