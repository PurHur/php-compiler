<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #3340 */
#[Group('ReflectionParameterAttributes')]
final class ReflectionParameterAttributesTest extends TestCase
{
    public function testReflectionParameterGetAttributes(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\Attribute]
class Route { public function __construct(public string $path) {} }

class C {
    public function m(#[Route('/x')] string $id) {}
}

$rp = new ReflectionMethod(C::class, 'm');
$params = $rp->getParameters();
$attrs = $params[0]->getAttributes(Route::class);
echo count($attrs), "\n";
echo $attrs[0]->getName();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_parameter_attributes.php'));
        $this->assertSame("1\nRoute", ob_get_clean());
    }

    public function testReflectionAttributeGetArgumentsNamed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\Attribute]
class Route { public function __construct(public string $path) {} }

class C {
    public function m(#[Route(path: '/x')] string $id) {}
}

$rp = new ReflectionMethod(C::class, 'm');
$params = $rp->getParameters();
$attrs = $params[0]->getAttributes(Route::class);
$args = $attrs[0]->getArguments();
echo $args['path'];
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_parameter_attr_args.php'));
        $this->assertSame('/x', ob_get_clean());
    }
}
