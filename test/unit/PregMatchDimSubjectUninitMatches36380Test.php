<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #36380 — preg_match / similar_text with dim subject + uninit by-ref out-param.
 * ZEND_SEND_REF must bind the CV, not the subject dim cell (Parsedown automatic_link).
 *
 * php-src: Zend/zend_execute.c ZEND_SEND_REF; ext/pcre/php_pcre.c
 *
 * @group unit
 */
final class PregMatchDimSubjectUninitMatches36380Test extends TestCase
{
    public function testPregMatchDimSubjectUninitMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/preg_match_dim_subject_uninit_matches_36380.php';
        $this->assertFileExists($src);

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php')
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, "VM rc=$rc out=".implode("\n", $out));
        $this->assertSame('m=["x"] text="x"', rtrim(implode("\n", $out)));
    }

    public function testSimilarTextDimSubjectUninitPercentVm(): void
    {
        $root = dirname(__DIR__, 2);
        $script = <<<'PHP'
<?php
class P {
    public function run(array $Ex): void {
        similar_text($Ex['text'], 'hello', $p);
        echo 'p=', json_encode($p), ' text=', json_encode($Ex['text']), "\n";
    }
}
(new P())->run(['text' => 'hello']);
PHP;
        $path = sys_get_temp_dir().'/phpc_similar_dim_pct_36380_'.getmypid().'.php';
        file_put_contents($path, $script);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php')
                .' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, "VM rc=$rc out=".implode("\n", $out));
            $this->assertSame('p=100 text="hello"', rtrim(implode("\n", $out)));
        } finally {
            @unlink($path);
        }
    }
}
