<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** CLI -d error_reporting and dynamic property E_DEPRECATED (#11558, #19848). */
final class DynamicPropertyDeprecationCliTest extends TestCase
{
    public function testVmDashDErrorReportingEmitsDynamicPropertyDeprecation(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        if (false === $vm) {
            $this->markTestSkipped('bin/vm.php missing');
        }

        $cmd = [
            PHP_BINARY,
            '-d',
            'error_reporting=0',
            '-d',
            'display_errors=0',
            $vm,
            '-d',
            'error_reporting=E_ALL',
            '-r',
            'class C{}; $c=new C; $c->x=1;',
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        $this->assertStringContainsString(
            'Creation of dynamic property C::$x is deprecated',
            $result['stderr']
        );
    }

    /**
     * Host {@code php -d error_reporting=E_ALL bin/vm.php} must enable guest deprecations (#19848).
     */
    public function testHostDashDErrorReportingEmitsDynamicPropertyDeprecation(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        if (false === $vm) {
            $this->markTestSkipped('bin/vm.php missing');
        }

        $script = tempnam(sys_get_temp_dir(), 'dynprop');
        $this->assertNotFalse($script);
        file_put_contents($script, "<?php\nclass C {}\n\$c = new C;\n\$c->x = 1;\necho \$c->x, \"\\n\";\n");

        try {
            $cmd = [
                PHP_BINARY,
                '-d',
                'error_reporting=E_ALL',
                '-d',
                'display_errors=0',
                $vm,
                $script,
            ];
            $result = $this->runCommand($cmd, $repoRoot);
            $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
            $this->assertStringContainsString(
                'Creation of dynamic property C::$x is deprecated',
                $result['stderr']
            );
            $this->assertStringContainsString("1\n", $result['stdout']);
        } finally {
            @unlink($script);
        }
    }

    /**
     * Explicit PROFILE=8.2 guest default is Zend E_ALL — dynamic prop E_DEPRECATED without -d (#29195).
     */
    public function testProfile82DefaultStartupEmitsDynamicPropertyDeprecation(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        if (false === $vm) {
            $this->markTestSkipped('bin/vm.php missing');
        }

        $script = tempnam(sys_get_temp_dir(), 'dynprop82');
        $this->assertNotFalse($script);
        file_put_contents($script, "<?php\nclass C {}\n\$c = new C;\n\$c->x = 1;\necho \$c->x, \"\\n\";\n");

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $cmd = [
                PHP_BINARY,
                '-d',
                'error_reporting=0',
                '-d',
                'display_errors=0',
                $vm,
                $script,
            ];
            $result = $this->runCommand($cmd, $repoRoot);
            $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
            $this->assertStringContainsString(
                'Creation of dynamic property C::$x is deprecated',
                $result['stderr']
            );
            $this->assertStringContainsString("1\n", $result['stdout']);
        } finally {
            @unlink($script);
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /**
     * PROFILE=8.1 must not emit dynamic-property E_DEPRECATED (Zend 8.1; #29195).
     */
    public function testProfile81SilentOnDynamicPropertyEvenWithEall(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        if (false === $vm) {
            $this->markTestSkipped('bin/vm.php missing');
        }

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.1');
        try {
            $cmd = [
                PHP_BINARY,
                '-d',
                'error_reporting=0',
                '-d',
                'display_errors=0',
                $vm,
                '-d',
                'error_reporting=E_ALL',
                '-r',
                'class C{}; $c=new C; $c->x=1; echo $c->x, "\n";',
            ];
            $result = $this->runCommand($cmd, $repoRoot);
            $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
            $this->assertStringNotContainsString(
                'Creation of dynamic property C::$x is deprecated',
                $result['stderr']
            );
            $this->assertStringContainsString("1\n", $result['stdout']);
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /**
     * Host {@code -d error_reporting=0} must not clear guest default (#2055); VERSION 8.2+ guest
     * default is Zend E_ALL including E_DEPRECATED even without PROFILE env var (#30443).
     */
    public function testHostErrorReportingZeroKeepsGuestDefaultWithDeprecationOn82(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        if (false === $vm) {
            $this->markTestSkipped('bin/vm.php missing');
        }

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $cmd = [
                PHP_BINARY,
                '-d',
                'error_reporting=0',
                '-d',
                'display_errors=0',
                $vm,
                '-r',
                'class C{}; $c=new C; $c->x=1; echo $c->x, "\n";',
            ];
            $result = $this->runCommand($cmd, $repoRoot);
            $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
            $this->assertStringContainsString(
                'Creation of dynamic property C::$x is deprecated',
                $result['stderr']
            );
            $this->assertStringContainsString("1\n", $result['stdout']);
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /**
     * Host {@code -d error_reporting=22527} (E_ALL without E_DEPRECATED) must suppress guest
     * null-string builtin deprecations like Zend (#30474).
     */
    public function testHostDashDErrorReportingWithoutDeprecatedSuppressesNullStringDeprecation(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        if (false === $vm) {
            $this->markTestSkipped('bin/vm.php missing');
        }

        $script = tempnam(sys_get_temp_dir(), 'domnull');
        $this->assertNotFalse($script);
        file_put_contents(
            $script,
            "<?php\n\$d = new DOMDocument();\n\$d->loadXML('<r id=\"x\"/>');\n"
            . "var_export(\$d->getElementById(null));\necho \"\\n\";\n"
        );

        try {
            $cmd = [
                PHP_BINARY,
                '-d',
                'error_reporting=22527',
                '-d',
                'display_errors=0',
                $vm,
                $script,
            ];
            $result = $this->runCommand($cmd, $repoRoot);
            $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
            $this->assertStringNotContainsString('deprecated', strtolower($result['stderr']));
            $this->assertStringContainsString("NULL\n", $result['stdout']);
        } finally {
            @unlink($script);
        }
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCommand(array $cmd, string $cwd): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [
            'code' => $code,
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => $stderr !== false ? $stderr : '',
        ];
    }
}
