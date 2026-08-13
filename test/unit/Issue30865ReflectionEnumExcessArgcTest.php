<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess/missing argc → ArgumentCountError for ReflectionEnum methods (#30865).
 *
 * php-src: ext/reflection/php_reflection.c / php_reflection.stub.php
 */
final class Issue30865ReflectionEnumExcessArgcTest extends TestCase
{
    public function testVmExcessAndMissingArgcThrowsArgumentCountError(): void
    {
        $path = __DIR__.'/../repro/reflection_enum_excess_argc_30865.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'reflection_enum_excess_argc_30865.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ArgumentCountError: ReflectionEnum::getCases() expects exactly 0 arguments, 1 given\n"
            ."ArgumentCountError: ReflectionEnum::hasCase() expects exactly 1 argument, 2 given\n"
            ."ArgumentCountError: ReflectionEnum::getCase() expects exactly 1 argument, 2 given\n"
            ."ArgumentCountError: ReflectionEnum::hasCase() expects exactly 1 argument, 0 given\n"
            ."ArgumentCountError: ReflectionEnum::getCase() expects exactly 1 argument, 0 given\n"
            ."ok=1,1,A\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
