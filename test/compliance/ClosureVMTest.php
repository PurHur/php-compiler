<?php

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/** VM compliance for anonymous closures and arrow functions (issues #72, #142). */
class ClosureVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach (['closure_simple.phpt', 'closure_arrow.phpt', 'closure_in_array.phpt', 'closure_array_element_call.phpt', 'closure_use.phpt', 'closure_array_map.phpt'] as $file) {
            $path = __DIR__ . '/cases/language/' . $file;
            $name = preg_replace('/\.phpt$/', '', $file) ?: $file;
            yield $name => self::parsePHPT($path, $file);
        }
    }
}
