<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: createFromFormat invalid clock overflows + warning offset (#30972).
 *
 * php-src: ext/date/lib/parse_date.c — timelib_parse_from_format
 *
 * @group llvm
 * @group aot
 */
final class Issue30972CreateFromFormatClockOverflowAotTest extends TestCase
{
    public function testAotClockOverflowAndCalendarWarningKey(): void
    {
        $this->compileAndAssert(
            <<<'PHP'
<?php
$h25 = DateTime::createFromFormat('H:i', '25:00');
echo $h25->format('H:i:s'), "\n";
$e = DateTime::getLastErrors();
echo $e['warnings'][5], "\n";

$i60 = DateTime::createFromFormat('H:i', '12:60');
echo $i60->format('H:i:s'), "\n";

$imm = DateTimeImmutable::createFromFormat('H:i', '25:00');
echo $imm->format('H:i:s'), "\n";

$fn = date_create_from_format('Y-m-d H:i:s', '2024-01-01 25:00:00');
echo $fn->format('Y-m-d H:i:s'), "\n";
$fe = date_get_last_errors();
echo $fe['warnings'][19], "\n";

$cal = DateTime::createFromFormat('Y-m-d H:i:s', '2024-02-31 12:00:00');
echo $cal->format('Y-m-d H:i:s'), "\n";
$ce = DateTime::getLastErrors();
echo $ce['warnings'][19], "\n";

$bang = DateTime::createFromFormat('!H:i', '25:00');
echo $bang->format('Y-m-d H:i:s'), "\n";
PHP,
            "01:00:00\n"
            ."The parsed time was invalid\n"
            ."13:00:00\n"
            ."01:00:00\n"
            ."2024-01-02 01:00:00\n"
            ."The parsed time was invalid\n"
            ."2024-03-02 12:00:00\n"
            ."The parsed date was invalid\n"
            ."1970-01-02 01:00:00\n"
        );
    }

    private function compileAndAssert(string $code, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30972_'.getmypid().'_'.mt_rand(1000, 9999).'.php';
        $bin = sys_get_temp_dir().'/phpc_30972_'.getmypid().'_'.mt_rand(1000, 9999).'.bin';
        file_put_contents($src, $code);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.$i);
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
