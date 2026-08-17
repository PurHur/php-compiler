<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT compliance for eval() — MCJIT execute may segfault in harness (#98); AOT covered by eval_inline.phpt. */
final class EvalJitTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }

    public static function providePHPTests(): \Generator
    {
        if (!self::jitExecuteAvailable()) {
            return;
        }
        foreach ([
            'eval_jit.phpt',
            'eval_basic.phpt',
            'eval_magic_consts.phpt',
            'eval_parse_error.phpt',
            'eval_return_value.phpt',
            'eval_dynamic_fn.phpt',
            'eval_inherits_class_scope.phpt',
        ] as $file) {
            $path = __DIR__.'/../compliance/cases/language/'.$file;
            yield $file => self::parsePHPT($path, $file);
        }
    }

    private static function jitExecuteAvailable(): bool
    {
        $probe = sys_get_temp_dir().'/phpc-jit-eval-probe.php';
        file_put_contents($probe, "<?php echo \"ok\\n\";");
        $bin = realpath(__DIR__.'/../../bin/jit.php');
        if (false === $bin) {
            return false;
        }
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($bin).' '.escapeshellarg($probe).' 2>/dev/null';
        exec($cmd, $out, $code);

        return 0 === $code && isset($out[0]) && 'ok' === $out[0];
    }
}
