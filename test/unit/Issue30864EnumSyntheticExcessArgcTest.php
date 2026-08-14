<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess/missing argc → ArgumentCountError for Enum::cases/from/tryFrom (#30864).
 *
 * php-src: Zend/zend_enum.c / Zend/zend_enum.stub.php
 */
final class Issue30864EnumSyntheticExcessArgcTest extends TestCase
{
    public function testVmExcessAndMissingArgcThrowsArgumentCountError(): void
    {
        $path = __DIR__.'/../repro/enum_synthetic_excess_argc_30864.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'enum_synthetic_excess_argc_30864.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ArgumentCountError: E::cases() expects exactly 0 arguments, 1 given\n"
            ."ArgumentCountError: E::from() expects exactly 1 argument, 2 given\n"
            ."ArgumentCountError: E::tryFrom() expects exactly 1 argument, 2 given\n"
            ."ArgumentCountError: E::from() expects exactly 1 argument, 0 given\n"
            ."ArgumentCountError: E::tryFrom() expects exactly 1 argument, 0 given\n"
            ."ok=A,1,NULL\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
