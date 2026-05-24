<?php

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/** VM compliance for invokable objects / __invoke (issue #1232). */
class InvokeObjectVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach (['invoke_object.phpt'] as $file) {
            $path = __DIR__ . '/cases/language/' . $file;
            $name = preg_replace('/\.phpt$/', '', $file) ?: $file;
            yield $name => self::parsePHPT($path, $file);
        }
    }
}
