<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT CLI execute gate for examples/006-FileUploadWeb multipart $_FILES (#1999).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group fileuploadweb
 * @group fileuploadweb-aot-execute
 */
final class FileUploadWebAotExecuteTest extends TestCase
{
    private string $repoRoot;

    private string $binary;

    protected function setUp(): void
    {
        $gate = getenv('FILE_UPLOAD_WEB_AOT_SMOKE_GATE');
        if (false === $gate || '' === $gate || '1' !== $gate) {
            $this->markTestSkipped(
                'FILE_UPLOAD_WEB_AOT_SMOKE_GATE=0 — set to 1 to run FileUploadWeb AOT execute tests (#1999)'
            );
        }
        $this->repoRoot = dirname(__DIR__, 2);
        $project = $this->repoRoot.'/examples/006-FileUploadWeb';
        if (!is_file($project.'/example.php')) {
            $this->markTestSkipped('examples/006-FileUploadWeb missing (#1999)');
        }
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $phpc = $this->repoRoot.'/phpc';
        if (!is_file($phpc)) {
            $this->markTestSkipped('phpc wrapper missing');
        }

        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', $project],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stderr = false !== $stderr ? $stderr : '';
        $this->assertSame(0, $exit, 'phpc build --project failed: '.substr($stderr, 0, 500));

        $this->binary = $project.'/.phpc/bin/app';
        $this->assertFileExists($this->binary);
    }

    public function testMultipartUploadPopulatesNestedFiles(): void
    {
        $env = $this->baseEnv();
        $env['REQUEST_METHOD'] = 'POST';
        $env['REQUEST_BODY'] = "--phpcFileB\r\n"
            ."Content-Disposition: form-data; name=\"doc\"; filename=\"README.md\"\r\n"
            ."Content-Type: text/plain\r\n\r\n"
            ."bytes\r\n"
            ."--phpcFileB--\r\n";
        $env['CONTENT_TYPE'] = 'multipart/form-data; boundary=phpcFileB';
        $env['SCRIPT_NAME'] = '/example.php';
        $env['REQUEST_URI'] = '/example.php';

        $out = $this->runBinary($env);
        $this->assertStringContainsString('Uploaded: README.md', $out);
    }

    /**
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * @param array<string, string> $env
     */
    private function runBinary(array $env): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $run = proc_open([$this->binary], $descriptorSpec, $pipes, null, $env);
        $this->assertIsResource($run);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($run);
        $this->assertSame(0, $exitCode, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? $stdout : '';
    }
}
