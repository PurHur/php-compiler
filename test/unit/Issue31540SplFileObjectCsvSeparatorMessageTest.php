<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplFileObject::fgetcsv/fputcsv empty separator ValueError cites method + arg index (#31540).
 *
 * php-src: ext/spl/spl_directory.c — zim_SplFileObject_fgetcsv / fputcsv.
 */
final class Issue31540SplFileObjectCsvSeparatorMessageTest extends TestCase
{
    public function testVmEmptySeparatorValueErrorMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_splfileobject_csv_separator_message.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31540_splfileobject_csv_separator_message.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "fgetcsv: ValueError: SplFileObject::fgetcsv(): Argument #1 (\$separator) must be a single character\n"
            ."fputcsv: ValueError: SplFileObject::fputcsv(): Argument #2 (\$separator) must be a single character\n",
            $out
        );
    }

    public function testVmSetCsvControlEmptySeparatorCitesMethod(): void
    {
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
$f = tempnam(sys_get_temp_dir(), 'sfo');
file_put_contents($f, "a,b\n");
$o = new SplFileObject($f);
try {
    $o->setCsvControl('');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
@unlink($f);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31540_setcsvcontrol_separator.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ValueError: SplFileObject::setCsvControl(): Argument #1 (\$separator) must be a single character\n",
            $out
        );
    }
}
