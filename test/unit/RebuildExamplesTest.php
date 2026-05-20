<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * examples/README.md benchmark pipeline (issue #60).
 */
final class RebuildExamplesTest extends TestCase
{
    public function testSimpleWebAotBenchmarkUsesRuntimeQueryString(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/script/rebuild-examples.php');
        $this->assertNotFalse($script);
        $this->assertStringContainsString("'aot_compile_time_query' => false", $script);
        $this->assertStringContainsString("'QUERY_STRING' => 'name=World'", $script);
        $this->assertStringContainsString('$profile[\'aot_compile_time_query\']', $script);
        $this->assertStringContainsString('$profile[\'aot_run_env\']', $script);
    }
}
