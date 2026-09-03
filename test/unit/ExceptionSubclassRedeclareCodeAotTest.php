<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Slim HttpNotFoundException redeclares Exception::$code / $message with new defaults (#36382).
 * AOT seedThrowable must mark those slots protected (zend_exceptions.stub.php) or composition fatals.
 *
 * @group llvm
 */
final class ExceptionSubclassRedeclareCodeAotTest extends TestCase
{
    public function testAotAllowsProtectedCodeRedeclareOnExceptionSubclass(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/exception_subclass_redeclare_code.php';
        $bin = sys_get_temp_dir().'/phpc_exc_redecl_'.bin2hex(random_bytes(4));
        $cmd = 'cd '.escapeshellarg($root)
            .' && php bin/compile.php -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertFileExists($bin);
        $run = [];
        exec(escapeshellarg($bin).' 2>&1', $run, $rrc);
        @unlink($bin);
        $this->assertSame(0, $rrc, implode("\n", $run));
        $this->assertSame("ok 404|Not found.\n", implode("\n", $run)."\n");
    }
}
