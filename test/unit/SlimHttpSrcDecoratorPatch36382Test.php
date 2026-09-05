<?php
declare(strict_types=1);
namespace PHPCompiler\Test\Unit;
use PHPUnit\Framework\TestCase;
final class SlimHttpSrcDecoratorPatch36382Test extends TestCase
{
    public function testPatchIsIdempotent(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/SlimHttpSRC_36382_'.getmypid().'.php';
        $seed = <<<'SRC'
<?php
namespace Slim\Factory\Psr17;
class SlimHttpServerRequestCreator
{
    protected static string $serverRequestDecoratorClass = 'Slim\Http\ServerRequest';
    public static function isServerRequestDecoratorAvailable(): bool
    {
        return class_exists(static::$serverRequestDecoratorClass);
    }
}
SRC;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-slim-http-src-decorator-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertStringContainsString('literal class_exists for decorator', $patched);
        $this->assertStringContainsString("class_exists('Slim\\\\Http\\\\ServerRequest')", $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2);
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
    }
}
