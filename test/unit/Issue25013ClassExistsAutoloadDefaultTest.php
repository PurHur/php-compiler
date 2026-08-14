<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * class_exists Reflection $autoload default true (#25013).
 *
 * php-src: Zend/zend_builtin_functions.stub.php
 */
final class Issue25013ClassExistsAutoloadDefaultTest extends TestCase
{
    public function testVmReflectionAutoloadDefaultIsTrueAndOmittedArgStillAutoloads(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_25013_class_exists_autoload_default.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_25013_class_exists_autoload_default.php');
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        $this->assertSame(
            "autoload_name=autoload\n".
            "autoload_default=true\n".
            "required=1\n".
            "hits=MissingA25013\n",
            $out
        );
    }
}
