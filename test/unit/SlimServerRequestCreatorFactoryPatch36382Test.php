<?php
declare(strict_types=1);
namespace PHPCompiler\Test\Unit;
use PHPUnit\Framework\TestCase;
final class SlimServerRequestCreatorFactoryPatch36382Test extends TestCase
{
    public function testPatchIsIdempotent(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/SRCF_36382_'.getmypid().'.php';
        $seed = <<<'SRC'
<?php
namespace Slim\Factory;
use Slim\Factory\Psr17\Psr17Factory;
use Slim\Factory\Psr17\Psr17FactoryProvider;
use Slim\Factory\Psr17\SlimHttpServerRequestCreator;
use Slim\Interfaces\ServerRequestCreatorInterface;
class ServerRequestCreatorFactory
{
    protected static $serverRequestCreator = null;
    protected static $psr17FactoryProvider = null;
    public static function determineServerRequestCreator(): ServerRequestCreatorInterface
    {
        if (static::$serverRequestCreator) {
            return static::attemptServerRequestCreatorDecoration(static::$serverRequestCreator);
        }

        $psr17FactoryProvider = static::$psr17FactoryProvider ?? new Psr17FactoryProvider();

        /** @var Psr17Factory $psr17Factory */
        foreach ($psr17FactoryProvider->getFactories() as $psr17Factory) {
            if ($psr17Factory::isServerRequestCreatorAvailable()) {
                $serverRequestCreator = $psr17Factory::getServerRequestCreator();
                return static::attemptServerRequestCreatorDecoration($serverRequestCreator);
            }
        }
        throw new \RuntimeException('none');
    }
    protected static function attemptServerRequestCreatorDecoration($x) { return $x; }
}
SRC;
        file_put_contents($tmp, $seed);
        $script = $root.'/script/composer/patch-slim-server-request-creator-factory-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $patched = file_get_contents($tmp);
        $this->assertStringContainsString('prefer Nyholm before factory loop', $patched);
        $this->assertStringContainsString('NyholmPsr17Factory::isServerRequestCreatorAvailable', $patched);
        $this->assertStringContainsString('use Slim\\Factory\\Psr17\\NyholmPsr17Factory;', $patched);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out2, $rc2);
        $this->assertSame(0, $rc2);
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
    }
}
