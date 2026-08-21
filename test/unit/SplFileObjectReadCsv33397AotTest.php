<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::READ_CSV yields CSV field arrays on current/foreach (#33397).
 *
 * Locks the #33448 CUR_LINE re-parse (no CSV row in construct `__spl_ht`).
 *
 * @see php-src ext/spl/spl_directory.c spl_filesystem_file_read_csv / SPL_FILE_OBJECT_READ_CSV
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectReadCsv33397AotTest extends TestCase
{
    public function testZendAndVmMatch(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_read_csv_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('0:["1","2"]', $zend);
        $this->assertStringContainsString('cur:["1","2"]', $zend);
        $this->assertStringContainsString('fgets:"1,2\n"', $zend);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame($zend, implode("\n", $vmOut)."\n");
    }

    public function testHelperCachesLineNotHtForReadCsv(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_read_csv.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('FLAG_READ_CSV', $helper);
        $this->assertStringContainsString('#33397', $helper);
        $this->assertStringContainsString('emitReadCsvLineToValueBox', $helper);
        // #33448: re-parse CUR_LINE; never store CSV row into construct __spl_ht.
        $this->assertStringContainsString('Does not write PROP_HT', $helper);
        $this->assertDoesNotMatchRegularExpression(
            '/emitReadCsvLineToValueBox[\s\S]*?storeHashtableProp\(\$context, \$obj, self::PROP_HT/',
            $helper
        );
        $outer = (string) file_get_contents($root.'/lib/VM/SplOuterIteratorHt.php');
        $this->assertStringNotContainsString("'splfileobject'", $outer);
    }
}
