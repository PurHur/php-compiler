<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — FastRoute Dispatcher GroupCountBased ctor avoids list()-to-property.
 *
 * @group aot
 */
final class FastRouteGroupCountCtorPatch36382Test extends TestCase
{
    public function testPatchRewritesListAssign(): void
    {
        $repo = dirname(__DIR__, 2);
        $patch = $repo.'/script/composer/patch-fastroute-groupcount-ctor-36382.php';
        $dir = sys_get_temp_dir().'/phpc_gc_36382_'.bin2hex(random_bytes(4));
        mkdir($dir);
        $tmp = $dir.'/GroupCountBased.php';
        file_put_contents($tmp, <<<'PHP'
<?php
namespace FastRoute\Dispatcher;
class GroupCountBased {
    protected $staticRouteMap = [];
    protected $variableRouteData = [];
    public function __construct($data)
    {
        list($this->staticRouteMap, $this->variableRouteData) = $data;
    }
}
PHP);
        exec('php '.escapeshellarg($patch).' '.escapeshellarg($tmp).' 2>&1', $out, $ec);
        $this->assertSame(0, $ec, implode("\n", $out));
        $text = (string) file_get_contents($tmp);
        $this->assertStringContainsString('avoid list() into dispatcher props', $text);
        $this->assertStringContainsString('$this->staticRouteMap = $data[0]', $text);
        @unlink($tmp);
        @rmdir($dir);
    }
}
