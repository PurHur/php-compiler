<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — Slim NyholmPsr17Factory concrete class-ref patch.
 */
final class SlimNyholmPsr17FactoryPatch36382Test extends TestCase
{
    public function testPatchIsIdempotentAndUsesConcreteNyholmClasses(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/NyholmPsr17Factory_36382_'.getmypid().'.php';
        $seed = <<<'PHP'
<?php
namespace Slim\Factory\Psr17;
use Slim\Interfaces\ServerRequestCreatorInterface;
class NyholmPsr17Factory extends Psr17Factory
{
    protected static string $responseFactoryClass = 'Nyholm\Psr7\Factory\Psr17Factory';
    protected static string $streamFactoryClass = 'Nyholm\Psr7\Factory\Psr17Factory';
    protected static string $serverRequestCreatorClass = 'Nyholm\Psr7Server\ServerRequestCreator';
    protected static string $serverRequestCreatorMethod = 'fromGlobals';

    public static function getServerRequestCreator(): ServerRequestCreatorInterface
    {
        /*
         * Nyholm Psr17Factory implements all factories in one unified
         * factory which implements all of the PSR-17 factory interfaces
         */
        $psr17Factory = new static::$responseFactoryClass();

        $serverRequestCreator = new static::$serverRequestCreatorClass(
            $psr17Factory,
            $psr17Factory,
            $psr17Factory,
            $psr17Factory
        );

        return new ServerRequestCreator($serverRequestCreator, static::$serverRequestCreatorMethod);
    }

    public static function isServerRequestCreatorAvailable(): bool
    {
        return (
            parent::isServerRequestCreatorAvailable()
            && class_exists(static::$responseFactoryClass)
        );
    }
}
PHP;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-slim-nyholm-psr17-factory-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertNotFalse($patched);
        $this->assertStringContainsString('concrete Nyholm class refs', $patched);
        $this->assertStringContainsString('Nyholm\\Psr7Server\\ServerRequestCreator::class', $patched);
        $this->assertStringContainsString('new \\Nyholm\\Psr7\\Factory\\Psr17Factory()', $patched);
        $this->assertStringNotContainsString('new static::$responseFactoryClass()', $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2, implode("\n", $out2));
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
    }
}
