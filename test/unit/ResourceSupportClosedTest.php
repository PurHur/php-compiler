<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Closed resource zval labels for gettype()/get_debug_type() (#5147). */
final class ResourceSupportClosedTest extends TestCase
{
    public function testClosedStreamReportsResourceClosedLabels(): void
    {
        ob_start();
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
$h = fopen('php://memory', 'r+');
fclose($h);
echo gettype($h), "\n";
echo get_debug_type($h), "\n";
PHP, 'gettype_closed_resource.php'));
        $output = ob_get_clean();

        $this->assertSame("resource (closed)\nresource (closed)\n", $output);
    }
}
