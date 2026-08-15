<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for preg_split/spl_autoload_register/iterator_to_array/
 * iterator_count/get_mangled_object_vars (#30575).
 *
 * php-src: ext/pcre/php_pcre.c, ext/spl/php_spl.c, Zend/zend_builtin_functions.c
 */
final class Issue30575MiscExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $path = __DIR__.'/../repro/issue_30575_misc_argc_ace.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30575_misc_argc_ace.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            'preg_split("/,/", "a,b", -1, 0, "x") => ArgumentCountError: preg_split() expects at most 4 arguments, 5 given'."\n"
            .'spl_autoload_register(fn() => null, true, true, "x") => ArgumentCountError: spl_autoload_register() expects at most 3 arguments, 4 given'."\n"
            .'iterator_to_array(new ArrayIterator([1]), true, "x") => ArgumentCountError: iterator_to_array() expects at most 2 arguments, 3 given'."\n"
            .'iterator_count(new ArrayIterator([1]), "x") => ArgumentCountError: iterator_count() expects exactly 1 argument, 2 given'."\n"
            .'get_mangled_object_vars(new stdClass, "x") => ArgumentCountError: get_mangled_object_vars() expects exactly 1 argument, 2 given'."\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
