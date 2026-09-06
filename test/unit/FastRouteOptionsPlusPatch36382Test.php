<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** #36382 — FastRoute options += patch expands defaults via ?? coalesce (not isset-foreach). */
final class FastRouteOptionsPlusPatch36382Test extends TestCase
{
    public function testPatchRewritesBothDispatchers(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/fastroute_fn_36382_'.getmypid().'.php';
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
        if (!$options['cacheDisabled'] && file_exists($options['cacheFile'])) {
            $dispatchData = require $options['cacheFile'];
            if (!is_array($dispatchData)) {
                throw new \RuntimeException('Invalid cache file "' . $options['cacheFile'] . '"');
            }
            return new $options['dispatcher']($dispatchData);
        }
}
PHP;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-fastroute-options-plus-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('AOT (#36382): coalesce dispatcher options into $merged', $patched);
        $this->assertStringContainsString('$merged = [', $patched);
        $this->assertStringContainsString("\$options['routeParser'] ??", $patched);
        $this->assertStringContainsString('AOT (#36382): skip dynamic require $cacheFile', $patched);
        $this->assertStringNotContainsString('$options += [', $patched);
        $this->assertStringNotContainsString('if (!isset($options[$k]))', $patched);
        $this->assertStringNotContainsString('require $options[\'cacheFile\']', $patched);
        // Must not assign the assoc rebuild back onto the array param (#36382).
        $this->assertStringNotContainsString("\$options = [\n            'routeParser'", $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
    }

    public function testPatchUpgradesIssetForeachToCoalesce(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/fastroute_fn_isset_36382_'.getmypid().'.php';
        $seed = <<<'PHP'
<?php
namespace FastRoute;
function simpleDispatcher(callable $routeDefinitionCallback, array $options = [])
{
        // AOT (#36382): expand dispatcher options — array += does not fill missing keys under AOT.
        $defaults = [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($options[$k])) {
                $options[$k] = $v;
            }
        }
}
function cachedDispatcher(callable $routeDefinitionCallback, array $options = [])
{
        // AOT (#36382): expand dispatcher options — array += does not fill missing keys under AOT.
        $defaults = [
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'FastRoute\\RouteCollector',
            'cacheDisabled' => false,
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($options[$k])) {
                $options[$k] = $v;
            }
        }
}
PHP;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-fastroute-options-plus-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('coalesce dispatcher options into $merged', $patched);
        $this->assertStringNotContainsString('if (!isset($options[$k]))', $patched);
        $this->assertStringContainsString('$merged = [', $patched);
        @unlink($tmp);
    }
}
