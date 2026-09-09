<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ExtensionRegistry;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Runtime PHP_COMPILER_EXTENSIONS filter (#36204).
 */
final class ExtensionPolicySubsetTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_EXTENSIONS');
        unset($_ENV['PHP_COMPILER_EXTENSIONS'], $_SERVER['PHP_COMPILER_EXTENSIONS']);
        parent::tearDown();
    }

    public function testUnsetLoadsFullRegistry(): void
    {
        putenv('PHP_COMPILER_EXTENSIONS');
        unset($_ENV['PHP_COMPILER_EXTENSIONS'], $_SERVER['PHP_COMPILER_EXTENSIONS']);
        $all = ExtensionRegistry::defaultModules();
        self::assertSame(\count($all), \count(Runtime::modulesToLoad()));
        self::assertNull(Runtime::selectedExtensionDirectories($all));
    }

    public function testOnlyListPullsNoExtraWhenDepsEmpty(): void
    {
        putenv('PHP_COMPILER_EXTENSIONS='.Runtime::EXTENSIONS_HELLO_SUBSET);
        $_ENV['PHP_COMPILER_EXTENSIONS'] = Runtime::EXTENSIONS_HELLO_SUBSET;
        $loaded = Runtime::modulesToLoad();
        $dirs = [];
        foreach ($loaded as $module) {
            self::assertSame(
                1,
                preg_match('#\\\\ext\\\\([^\\\\]+)\\\\Module$#', \get_class($module), $m)
            );
            $dirs[] = $m[1];
        }
        sort($dirs);
        $expected = ['ctype', 'hash', 'random', 'spl', 'standard', 'types'];
        self::assertSame($expected, $dirs);
        $ordered = [];
        foreach (ExtensionRegistry::defaultModules() as $module) {
            preg_match('#\\\\ext\\\\([^\\\\]+)\\\\Module$#', \get_class($module), $m);
            if (\in_array($m[1], $expected, true)) {
                $ordered[] = $m[1];
            }
        }
        $gotOrder = [];
        foreach ($loaded as $module) {
            preg_match('#\\\\ext\\\\([^\\\\]+)\\\\Module$#', \get_class($module), $m);
            $gotOrder[] = $m[1];
        }
        self::assertSame($ordered, $gotOrder);
    }

    public function testMinusRemovesFromFullSet(): void
    {
        putenv('PHP_COMPILER_EXTENSIONS=-intl,-gmp');
        $_ENV['PHP_COMPILER_EXTENSIONS'] = '-intl,-gmp';
        $dirs = [];
        foreach (Runtime::modulesToLoad() as $module) {
            preg_match('#\\\\ext\\\\([^\\\\]+)\\\\Module$#', \get_class($module), $m);
            $dirs[$m[1]] = true;
        }
        self::assertArrayNotHasKey('intl', $dirs);
        self::assertArrayNotHasKey('gmp', $dirs);
        self::assertArrayHasKey('standard', $dirs);
        self::assertGreaterThan(70, \count($dirs));
    }

    public function testDomOnlyPullsLibxml(): void
    {
        putenv('PHP_COMPILER_EXTENSIONS=only:dom');
        $_ENV['PHP_COMPILER_EXTENSIONS'] = 'only:dom';
        $dirs = [];
        foreach (Runtime::modulesToLoad() as $module) {
            preg_match('#\\\\ext\\\\([^\\\\]+)\\\\Module$#', \get_class($module), $m);
            $dirs[] = $m[1];
        }
        self::assertSame(['libxml', 'dom'], $dirs);
    }

    public function testUnknownNameThrows(): void
    {
        putenv('PHP_COMPILER_EXTENSIONS=only:no_such_ext');
        $_ENV['PHP_COMPILER_EXTENSIONS'] = 'only:no_such_ext';
        $this->expectException(\InvalidArgumentException::class);
        Runtime::modulesToLoad();
    }
}
