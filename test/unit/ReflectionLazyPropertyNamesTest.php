<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** ReflectionClass::getLazyPropertyNames() — #6606. */
final class ReflectionLazyPropertyNamesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!CompilerVersion::supportsLazyObjectFactories()) {
            $this->markTestSkipped('Lazy object factories require stable PHP 8.4+ profile (#12375)');
        }
    }

    public function testMethodExistsAndLazyGhostTraitListsInstanceProperties(): void
    {
        $code = <<<'PHP'
<?php
var_export(method_exists(ReflectionClass::class, 'getLazyPropertyNames'));
echo "\n";
class Svc {
    use LazyGhostTrait;
    public string $id;
}
$names = (new ReflectionClass(Svc::class))->getLazyPropertyNames();
echo count($names), "\n";
echo $names[0], "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("true\n1\nid\n", ob_get_clean());
    }
}
