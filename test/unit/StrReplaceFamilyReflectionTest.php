<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** str_ireplace/substr_replace/strtr Reflection types (#23588). */
final class StrReplaceFamilyReflectionTest extends TestCase
{
    public function testReflectionTypesMatchZendStubs(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_23588_str_replace_family_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_23588.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "str_replace ret=array|string\n"
            ."  search array|string\n"
            ."  replace array|string\n"
            ."  subject array|string\n"
            ."  &?count (none)\n"
            ."str_ireplace ret=array|string\n"
            ."  search array|string\n"
            ."  replace array|string\n"
            ."  subject array|string\n"
            ."  &?count (none)\n"
            ."substr_replace ret=array|string\n"
            ."  string array|string\n"
            ."  replace array|string\n"
            ."  offset array|int\n"
            ."  ?length array|int|null\n"
            ."strtr ret=string\n"
            ."  string string\n"
            ."  from array|string\n"
            ."  ?to ?string\n"
            ."substr_count ret=int\n"
            ."  haystack string\n"
            ."  needle string\n"
            ."  ?offset int\n"
            ."  ?length ?int\n",
            ob_get_clean()
        );
    }
}
