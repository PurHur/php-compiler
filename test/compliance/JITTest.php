<?php

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Backend\VM\Runtime;

require_once __DIR__ . '/../BaseTest.php';

class JITTest extends BaseTest {

    protected static string $DIR = __DIR__;

    public function setUp(): void {
        $this->BIN = realpath(__DIR__ . '/../../bin/jit.php');
        try {
            \PHPLLVM\Chooser::choose();
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLVM not available for JIT tests: '.$e->getMessage());
        }
    }

}