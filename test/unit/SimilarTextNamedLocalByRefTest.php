<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class SimilarTextNamedLocalByRefTest extends TestCase
{
    public function testInitializedLocalPercentByRef(): void
    {
        $code = <<<'PHP'
<?php
$p = 0;
similar_text('hello', 'hallo', $p);
echo $p, "\n";
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'similar_text_percent.php'));
        self::assertSame("80\n", ob_get_clean());
    }
}
