<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * isset/empty/?? on dim of uninitialized typed properties is BP_VAR_IS (#31783).
 */
final class UninitTypedPropIssetDimTest extends TestCase
{
    /**
     * @covers issue #31783
     */
    public function testInstanceDimIssetEmptyCoalesceMatchZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__) . '/repro/maintainer_gap_isset_dim_uninit_typed.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_isset_dim_uninit_typed.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "isset_a0=0\n"
            ."empty_a0=1\n"
            ."isset_nk=0\n"
            ."empty_nk=1\n"
            ."coalesce_a0='d'\n"
            ."isset_s0=0\n"
            ."empty_s0=1\n"
            ."after\n",
            $out
        );
    }

    /**
     * @covers issue #31783
     */
    public function testStaticDimIssetEmptyCoalesceMatchZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__) . '/repro/maintainer_gap_isset_dim_uninit_static_typed.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_isset_dim_uninit_static_typed.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "isset0=0\n"
            ."empty0=1\n"
            ."coalesce='d'\n"
            ."issetn=0\n"
            ."after\n",
            $out
        );
    }
}
