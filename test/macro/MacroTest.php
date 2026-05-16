<?php

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Backend\VM\Runtime;

require_once __DIR__ . '/../BaseTest.php';

class MacroTest extends BaseTest {

    protected static string $DIR = __DIR__;

    public function setUp(): void {
        if (\PHP_VERSION_ID >= 80100) {
            $this->markTestSkipped('Macro preprocessor (Yay/Pre) is not compatible with this PHP version');
        }
        $this->BIN = realpath(__DIR__ . '/../bin/macro_compile.php');
    }

}