<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/**
 * VM compliance for include/require caller scope (#471, #477).
 *
 * @group include_scope
 */
final class IncludeScopeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $dir = __DIR__ . '/cases/language';
        foreach (new \GlobIterator($dir . '/include_scope*.phpt') as $test) {
            yield self::parsePHPT($test->getPathname(), $test->getBasename());
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/vm.php');
    }
}
