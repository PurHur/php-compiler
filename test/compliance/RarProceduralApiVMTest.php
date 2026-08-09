<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * rar_* procedural wrappers around RarArchive (#27878, PECL rar).
 */
final class RarProceduralApiVMTest extends TestCase
{
    public function test_procedural_functions_exist_and_open_missing_returns_false(): void
    {
        $root = dirname(__DIR__, 2);
        $script = sys_get_temp_dir().'/phpc_rar_proc_'.getmypid().'.php';
        file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);
foreach (['rar_open','rar_list','rar_entry_get','rar_solid_is','rar_comment_get','rar_broken_is','rar_allow_broken_set','rar_close'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}
$missing = rar_open('/tmp/phpc-definitely-missing-'.getmypid().'.rar');
echo 'missing=', ($missing === false) ? 'Y' : 'N', "\n";
PHP);

        $cmd = [PHP_BINARY, '-d', 'display_errors=1', $root.'/bin/vm.php', $script];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            array_merge($_ENV, ['PHP_COMPILER_PROFILE' => '8.4'])
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($script);

        $this->assertSame(0, $exit, trim((string) $stderr)."\n".(string) $stdout);
        $this->assertSame(
            "rar_open=Y\nrar_list=Y\nrar_entry_get=Y\nrar_solid_is=Y\nrar_comment_get=Y\nrar_broken_is=Y\nrar_allow_broken_set=Y\nrar_close=Y\nmissing=Y\n",
            $stdout,
            trim((string) $stderr)
        );
    }
}
