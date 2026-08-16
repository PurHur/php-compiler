<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * stream_filter_append/prepend null $filter_name under strict_types → TypeError (#31408).
 *
 * php-src: ext/standard/streamsfuncs.c / basic_functions.stub.php — string $filter_name
 */
final class Issue31408StreamFilterNullNameStrictTest extends TestCase
{
    public function testVmNullFilterNameStrictTypeError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_stream_filter_append_null_name.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_stream_filter_append_null_name.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "TypeError: stream_filter_append(): Argument #2 (\$filter_name) must be of type string, null given\n"
            ."TypeError: stream_filter_prepend(): Argument #2 (\$filter_name) must be of type string, null given\n",
            $out
        );
        $this->assertStringNotContainsString('unable to locate filter', $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
    }
}
