<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** #36382 — FastRoute options += patch expands defaults via isset. */
final class FastRouteOptionsPlusPatch36382Test extends TestCase
{
    public function testPatchRewritesBothDispatchers(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/fastroute_fn_36382_'.getmypid().'.php';
        $seed = file_get_contents(
            $root.'/test/fixtures/aot/projects/slim_hello_36382/vendor/nikic/fast-route/src/functions.php'
        );
        if (false === $seed || !str_contains($seed, '$options += [')) {
            // Fixture may already be patched — use pristine seed text.
            $seed = <<<'PHP'
<?php
namespace FastRoute;
function simpleDispatcher(callable $routeDefinitionCallback, array $options = [])
{
        $options += [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
        ];
}
function cachedDispatcher(callable $routeDefinitionCallback, array $options = [])
{
        $options += [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
            'cacheDisabled' => false,
        ];
}
PHP;
        }
        // If fixture already patched, restore += form for the test seed.
        if (str_contains($seed, 'AOT (#36382): expand dispatcher options')) {
            $seed = <<<'PHP'
<?php
namespace FastRoute;
function simpleDispatcher(callable $routeDefinitionCallback, array $options = [])
{
        $options += [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
        ];
}
function cachedDispatcher(callable $routeDefinitionCallback, array $options = [])
{
        $options += [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
            'cacheDisabled' => false,
        ];
}
PHP;
        }
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-fastroute-options-plus-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('AOT (#36382): expand dispatcher options', $patched);
        $this->assertStringContainsString('if (!isset($options[$k]))', $patched);
        $this->assertStringNotContainsString('$options += [', $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
    }
}
