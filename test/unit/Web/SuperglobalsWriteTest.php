<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #103: writable superglobals ($_GET assignment) in VM.
 */
final class SuperglobalsWriteTest extends TestCase
{
    public function testGetAssignThenRead(): void
    {
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', null);

        $code = <<<'PHP'
<?php
$_GET['x'] = '1';
echo $_GET['x'];
PHP;

        $this->assertSame(
            '1',
            $this->runVm($runtime, $code)
        );
    }

    public function testPostAssignThenRead(): void
    {
        putenv('REQUEST_METHOD=POST');
        putenv('REQUEST_BODY=');
        putenv('CONTENT_TYPE=application/x-www-form-urlencoded');

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', null);

        $code = <<<'PHP'
<?php
$_POST['token'] = 'abc';
echo $_POST['token'];
PHP;

        $this->assertSame(
            'abc',
            $this->runVm($runtime, $code)
        );

        putenv('REQUEST_METHOD');
        putenv('REQUEST_BODY');
        putenv('CONTENT_TYPE');
    }

    private function runVm(Runtime $runtime, string $code): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_sg_write_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $code);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            array_merge([PHP_BINARY, '-d', 'display_errors=0'], [realpath(__DIR__ . '/../../../bin/vm.php'), $tmp]),
            $descriptorSpec,
            $pipes,
            dirname(__DIR__, 3)
        );
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($tmp);

        return $out !== false ? $out : '';
    }
}
