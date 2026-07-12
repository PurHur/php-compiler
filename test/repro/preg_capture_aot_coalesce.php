<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../lib/AOT/LinkerProcessPolyfill.php';
if (!\function_exists('phpc_run_command')) {
    function phpc_run_command(string $command, ?array $env = null): ?array
    {
        return \PHPCompiler\AOT\LinkerProcessPolyfill::run($command, $env);
    }
}

use PHPCompiler\Runtime;

putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
putenv('PHP_COMPILER_HELPER_RUNTIME_O=1');
putenv('PHP_COMPILER_SELFHOST_AOT=0');
putenv('PHP_COMPILER_CACHE=0');
$_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';

$code = <<<'PHP'
<?php
preg_match("/b(oundary)=(\\w+)/", "boundary=x", $m);
echo $m[2] ?? "(none)", "\n";
PHP;

$scriptPath = sys_get_temp_dir().'/preg_cap_coalesce.php';
file_put_contents($scriptPath, $code);
$outPath = sys_get_temp_dir().'/preg_cap_coalesce_out';

$runtime = new Runtime(Runtime::MODE_AOT);
$block = $runtime->parseAndCompile($code, $scriptPath);
$runtime->standalone($block, $outPath, $code, $scriptPath);
if (is_executable($outPath)) {
    passthru($outPath);
}
