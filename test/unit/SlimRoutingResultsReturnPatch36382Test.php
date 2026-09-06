<?php
declare(strict_types=1);
namespace PHPCompiler\test\unit;
use PHPUnit\Framework\TestCase;
/** @group aot */
final class SlimRoutingResultsReturnPatch36382Test extends TestCase
{
    public function testPatchDropsArrayReturnTypes(): void
    {
        $repo = dirname(__DIR__, 2);
        $patch = $repo.'/script/composer/patch-slim-routing-results-return-36382.php';
        $tmp = sys_get_temp_dir().'/RoutingResults_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($tmp, <<<'PHP'
<?php
namespace Slim\Routing;
class RoutingResults {
    public function getRouteArguments(bool $urlDecode = true): array { return []; }
    public function getAllowedMethods(): array { return []; }
}
PHP);
        exec('php '.escapeshellarg($patch).' '.escapeshellarg($tmp).' 2>&1', $out, $ec);
        $this->assertSame(0, $ec, implode("\n", $out));
        $text = (string) file_get_contents($tmp);
        $this->assertStringContainsString('untyped getRouteArguments/getAllowedMethods', $text);
        $this->assertStringNotContainsString('getRouteArguments(bool $urlDecode = true): array', $text);
        @unlink($tmp);
    }
}
