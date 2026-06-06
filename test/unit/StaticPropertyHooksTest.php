<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Static property hooks are compile-error in PHP 8.4 (#6619). */
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
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage('Cannot declare hooks for static property');
            $rt->parseAndCompile($src, $path);
        } finally {
            @unlink($path);
        }
    }
}
