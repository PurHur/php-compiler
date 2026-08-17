<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** get_required_files / get_mangled_object_vars Reflection array (#27785). */
final class RequiredMangledReflectionTest extends TestCase
{
    public function testReflectionReturnArray(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_27785_required_mangled_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_27785.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "get_required_files=array\n"
            ."get_mangled_object_vars=array\n"
            ."get_included_files=array\n"
            ."required_ok=1\n"
            ."mangled_ok=1\n",
            ob_get_clean()
        );
    }
}
