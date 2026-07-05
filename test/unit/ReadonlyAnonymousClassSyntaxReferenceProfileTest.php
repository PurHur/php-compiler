<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** `new readonly class` enabled on 8.4.0-dev line (#16255, #16348). */
final class ReadonlyAnonymousClassSyntaxReferenceProfileTest extends TestCase
{
    public function testSupportsReadonlyAnonymousClassTrueOn84DevLine(): void
    {
        $this->assertTrue(CompilerVersion::supportsReadonlyAnonymousClass());
    }

    public function testRejectorAllowsMaintainerGapRepro(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_anonymous_readonly_class.php');
        $this->assertSame($code, ReadonlyAnonymousClassSyntaxRejector::reject($code, 'maintainer_gap_anonymous_readonly_class.php'));
    }

    public function testRuntimeCompilesMaintainerGapRepro(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_anonymous_readonly_class.php'),
            'maintainer_gap_anonymous_readonly_class.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testNamedReadonlyClassStillCompilesOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php readonly class R { public function __construct(public int $x = 1) {} } $o = new R(); var_export($o->x);',
            'named_readonly_class.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }
}
