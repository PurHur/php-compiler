<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** AOT include discovery must desugar pipe before PHPCfg parse (#4456). */
final class LiteralIncludeDiscoveryPipeTest extends TestCase
{
    public function testDiscoverDirectDoesNotFailOnPipeOperator(): void
    {
        $dir = sys_get_temp_dir().'/phpc_pipe_include_'.getmypid();
        $this->assertTrue(is_dir($dir) || mkdir($dir, 0775, true));
        $entry = $dir.'/entry.php';
        file_put_contents($entry, <<<'PHP'
<?php
echo "hi" |> strtoupper(...);
PHP
        );
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $paths = LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $entry);
            $this->assertIsArray($paths);
        } finally {
            @unlink($entry);
            @rmdir($dir);
        }
    }
}
