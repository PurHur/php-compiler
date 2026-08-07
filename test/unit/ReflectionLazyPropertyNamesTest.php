<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** ReflectionClass::getLazyPropertyNames() is a phantom vs php-src (#28516, re-#6606). */
final class ReflectionLazyPropertyNamesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!CompilerVersion::supportsLazyObjectFactories()) {
            $this->markTestSkipped('Lazy object factories require stable PHP 8.4+ profile (#12375)');
        }
    }

    public function testGetLazyPropertyNamesAbsentOnForwardProfile(): void
    {
        $code = <<<'PHP'
<?php
var_export(method_exists(ReflectionClass::class, 'getLazyPropertyNames'));
echo "\n";
echo method_exists(ReflectionClass::class, 'newLazyGhost') ? "newLazyGhost\n" : "missing\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("false\nnewLazyGhost\n", ob_get_clean());
    }
}
