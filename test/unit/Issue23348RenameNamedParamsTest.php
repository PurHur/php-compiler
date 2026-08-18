<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * rename() Reflection names from/to/context (not old_name/new_name) (#23348).
 *
 * php-src: ext/standard/file.stub.php
 */
final class Issue23348RenameNamedParamsTest extends TestCase
{
    public function testBuiltinParamNames(): void
    {
        self::assertSame(['from', 'to', 'context'], BuiltinParamNames::forFunction('rename'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('rename'),
            'from',
            'rename'
        ));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('rename'),
            'old_name',
            'rename'
        ));
    }

    public function testVmNamedFromToMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_23348_rename_named.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_23348_rename_named.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "from to context\n"
            ."true\n"
            ."x\n"
            ."legacy: Unknown named parameter \$old_name\n",
            $out
        );
    }
}
