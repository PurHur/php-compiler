<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * CI gate: shipped examples compile in VM and (when LLVM is present) AOT lint.
 *
 * @see https://github.com/PurHur/php-compiler/issues/203
 * @see https://github.com/PurHur/php-compiler/issues/243 (structured phpc lint per shipped example)
 * @see https://github.com/PurHur/php-compiler/issues/247 (002-StaticWeb compile.php build + execute)
 * @see https://github.com/PurHur/php-compiler/issues/282 (002-StaticWeb via ./phpc build)
 * @see https://github.com/PurHur/php-compiler/issues/309 (001-SimpleWeb AOT execute + QUERY_STRING refresh in this gate)
 * @see https://github.com/PurHur/php-compiler/issues/259 (001-SimpleWeb POST via $_REQUEST)
 * @see https://github.com/PurHur/php-compiler/issues/274 (minimal phpc.json beside web examples)
 * @see https://github.com/PurHur/php-compiler/issues/454 (003-MiniWebApp gate)
 * @see https://github.com/PurHur/php-compiler/issues/1887 (005-SessionsWeb serve + session cookie)
 * @see https://github.com/PurHur/php-compiler/issues/1946 (005-SessionsWeb AOT link gate)
 * @see https://github.com/PurHur/php-compiler/issues/1999 (006-FileUploadWeb multipart reference)
 */
final class ExamplesCompileTest extends TestCase
{
    private static ?bool $llvmReady = null;

    /**
     * Shipped 003-MiniWebApp: native AOT link via phpc build --project (#754; execute #764).
     *
     * @group miniwebapp
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function test003MiniWebAppBuildLinks(): void
    {
        if (!self::miniWebAppAotLinkGateEnabled()) {
            $this->markTestSkipped('MINIWEBAPP_AOT_LINK_GATE=0 — skip 003 project link gate (#754)');
        }
        $project = $this->miniWebAppProjectPath();
        $binary = $this->build003MiniWebAppProject($project);
        $this->assertFileExists($binary);
    }

    /**
     * 003 home route AOT execute (bundle literal __DIR__ for template includes, #764).
     *
     * Runs when MINIWEBAPP_AOT_EXECUTE_GATE=1 (default); link-only gate is test003MiniWebAppBuildLinks (#754).
     *
     * @group miniwebapp
     * @group llvm
     * @group aot
     * @group miniwebapp-aot-execute
     */
    public function test003MiniWebAppHomeRouteAotExecutes(): void
    {
        if (!self::miniWebAppAotExecuteGateEnabled()) {
            $this->markTestSkipped(
                'MINIWEBAPP_AOT_EXECUTE_GATE=0 — set to 1 (default) to run 003 AOT execute tests'
            );
        }
        $project = $this->miniWebAppProjectPath();
        $binary = $this->build003MiniWebAppProject($project);
        $publicDir = $project.'/public';
        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $env['SCRIPT_FILENAME'] = $publicDir.'/index.php';
        $env['SCRIPT_NAME'] = '/index.php';
        $env['DOCUMENT_ROOT'] = $publicDir;
        $env['REQUEST_METHOD'] = 'GET';
        $env['QUERY_STRING'] = 'route=home';

        $out = $this->runAotBinary($binary, $env);
        $this->assertNotSame('', $out, '003 AOT home route produced empty stdout (#764)');
        $this->assertStringContainsString('MiniWebApp', $out);
    }

    /**
     * 003-MiniWebApp AOT binary CLI execute with CGI env (#747, #764).
     *
     * Runs when MINIWEBAPP_AOT_EXECUTE_GATE=1 (default).
     *
     * @group miniwebapp
     * @group llvm
     * @group aot
     * @group miniwebapp-aot-execute
     */
    public function test003MiniWebAppExecutesWithCgiEnv(): void
    {
        if (!self::miniWebAppAotExecuteGateEnabled()) {
            $this->markTestSkipped(
                'MINIWEBAPP_AOT_EXECUTE_GATE=0 — set to 1 (default) to run 003 AOT execute tests'
            );
        }
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $project = $this->miniWebAppProjectPath();
        $binary = $this->build003MiniWebAppProject($project);
        $publicDir = $project.'/public';
        $this->assertDirectoryExists($publicDir);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $env['SCRIPT_FILENAME'] = $publicDir.'/index.php';
        $env['SCRIPT_NAME'] = '/index.php';
        $env['DOCUMENT_ROOT'] = $publicDir;
        $env['REQUEST_METHOD'] = 'GET';
        $env['QUERY_STRING'] = 'route=home';
        $env['PHPC_DEPLOY_ROOT'] = $project;

        $out = $this->runAotBinary($binary, $env);
        $this->assertNotSame('', $out, '003 AOT home route produced empty stdout (#764)');
        $this->assertStringContainsString('MiniWebApp', $out);
    }

    /**
     * @deprecated Use {@see test003MiniWebAppBuildLinks()} — kept for gate script regex (#791).
     */
    public function test003MiniWebAppEventuallyRuns(): void
    {
        $this->test003MiniWebAppBuildLinks();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideExamples(): array
    {
        $cases = [];
        $root = dirname(__DIR__, 2).'/examples';
        foreach (glob($root.'/*/example.php') ?: [] as $path) {
            $name = basename(dirname($path));
            $cases[$name] = [$path];
        }
        ksort($cases);

        return $cases;
    }

    /**
     * @dataProvider provideExamples
     */
    public function testVmLint(string $examplePath): void
    {
        $name = basename(dirname($examplePath));
        $this->runCli('vm.php', array_merge(self::vmExtraArgs($name), ['-l', $examplePath]));
    }

    /**
     * Structured lint (line + tracking issue) for each shipped example.
     *
     * @dataProvider provideExamples
     *
     * @see https://github.com/PurHur/php-compiler/issues/243
     */
    public function testPhpcLint(string $examplePath): void
    {
        $exit = $this->runLint([$examplePath]);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
    }

    /**
     * @dataProvider provideExamples
     *
     * @see https://github.com/PurHur/php-compiler/issues/243
     */
    public function testPhpcLintJsonClean(string $examplePath): void
    {
        $exit = $this->runLint(['--json', $examplePath]);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
        $decoded = json_decode($exit['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('issues', $decoded);
        $this->assertSame([], $decoded['issues']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideWebExampleManifestDirs(): array
    {
        $root = dirname(__DIR__, 2).'/examples';

        return [
            '001-SimpleWeb' => [$root.'/001-SimpleWeb'],
            '002-StaticWeb' => [$root.'/002-StaticWeb'],
            '004-ApiJson' => [$root.'/004-ApiJson'],
            '005-SessionsWeb' => [$root.'/005-SessionsWeb'],
            '006-FileUploadWeb' => [$root.'/006-FileUploadWeb'],
        ];
    }

    /**
     * @dataProvider provideWebExampleManifestDirs
     *
     * @see https://github.com/PurHur/php-compiler/issues/274
     */
    public function testWebExamplePhpcJsonEntryExists(string $exampleDir): void
    {
        $manifestPath = $exampleDir.'/phpc.json';
        $this->assertFileExists($manifestPath);
        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('entry', $decoded);
        $this->assertArrayHasKey('binary', $decoded);
        $this->assertIsString($decoded['entry']);
        $this->assertSame('example.php', $decoded['entry']);
        $this->assertSame('.phpc/bin/app', $decoded['binary']);
        $entryPath = $exampleDir.'/'.$decoded['entry'];
        $this->assertFileExists($entryPath, 'phpc.json entry must exist: '.$entryPath);
    }

    public function testPhpcLintDelegatesViaPhpc(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $example = $repoRoot.'/examples/000-HelloWorld/example.php';
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/phpc.php', 'lint', $example]
        );
        $exit = $this->runLintCommand($cmd, $repoRoot);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
    }

    public function testPhpcLintFailureSurfacesTrackingIssue(): void
    {
        $exit = $this->runLint(['-r', '<?php function f() { yield 1; }']);
        $this->assertSame(1, $exit['code']);
        $combined = $exit['stdout'].$exit['stderr'];
        $this->assertStringContainsString('unsupported', $combined);
        $this->assertStringContainsString('see #167', $combined);
        $this->assertMatchesRegularExpression('/line \d+/', $combined);
    }

    /**
     * @dataProvider provideExamples
     */
    public function testVmSmokeOutput(string $examplePath): void
    {
        $name = basename(dirname($examplePath));
        $out = $this->runCli('vm.php', array_merge(self::vmExtraArgs($name), [$examplePath]));
        foreach (self::smokeNeedles($name) as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
    }

    /**
     * Shipped 001-SimpleWeb: VM run with -p populates $_REQUEST from POST body (issue #259).
     */
    public function testVmSmokePost001SimpleWeb(): void
    {
        $examplePath = dirname(__DIR__, 2).'/examples/001-SimpleWeb/example.php';
        $this->assertFileExists($examplePath);
        $out = $this->runCli('vm.php', ['-p', 'name=PostExample', $examplePath]);
        $this->assertStringContainsString('Hello PostExample', $out);
    }

    /**
     * 005-SessionsWeb: phpc serve + cookie jar POST redirect flash (issue #1887).
     *
     * @group serve
     */
    public function test005SessionsWebServeFlashRoundTrip(): void
    {
        if (false !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS') && '' !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS')) {
            $this->markTestSkipped('PHP_COMPILER_SKIP_SERVE_TESTS is set');
        }
        if (!self::sessionsWebSmokeGateEnabled()) {
            $this->markTestSkipped('SESSIONS_WEB_SMOKE_GATE=0 — skip 005 serve flash gate (#1887)');
        }
        if (!$this->canBindLoopback()) {
            $this->markTestSkipped('Cannot bind loopback TCP');
        }
        if (!$this->commandExists('curl')) {
            $this->markTestSkipped('curl not available');
        }

        $repoRoot = dirname(__DIR__, 2);
        $docroot = $repoRoot.'/examples/005-SessionsWeb';
        $this->assertDirectoryExists($docroot);

        $port = $this->findFreeTcpPort();
        $addr = '127.0.0.1:'.$port;
        $phpc = $repoRoot.'/phpc';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'serve', $addr, $docroot],
            $descriptorSpec,
            $pipes,
            $repoRoot,
            null,
            ['suppress_errors' => true]
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        try {
            $this->waitForTcpPort($port);
            $jar = tempnam(sys_get_temp_dir(), 'phpc_sess_');
            $this->assertNotFalse($jar);
            $base = 'http://127.0.0.1:'.$port.'/example.php';

            $empty = $this->curlGetWithJar($base, $jar);
            $this->assertStringContainsString('No flash message yet', $empty);

            $postStatus = $this->curlPostWithJar($base, $jar, 'message=Saved');
            $this->assertSame('303', $postStatus);

            $flash = $this->curlGetWithJar($base, $jar);
            $this->assertStringContainsString('Flash: Saved', $flash);

            $after = $this->curlGetWithJar($base, $jar);
            $this->assertStringContainsString('No flash message yet', $after);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            if (isset($jar) && is_string($jar)) {
                @unlink($jar);
            }
        }
    }

    /**
     * 005-SessionsWeb: native AOT link via phpc build --project (#1946; execute #1891).
     *
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function test005SessionsWebAotLink(): void
    {
        if (!self::sessionsWebAotLinkGateEnabled()) {
            $this->markTestSkipped('SESSIONS_WEB_AOT_LINK_GATE=0 — skip 005 project link gate (#1946)');
        }
        $project = $this->sessionsWebProjectPath();
        $binary = $this->build005SessionsWebProject($project);
        $this->assertFileExists($binary);
    }

    /**
     * 006-FileUploadWeb: phpc serve + multipart POST (issue #1999).
     *
     * @group serve
     */
    public function test006FileUploadWebServeMultipart(): void
    {
        if (false !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS') && '' !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS')) {
            $this->markTestSkipped('PHP_COMPILER_SKIP_SERVE_TESTS is set');
        }
        if (!self::fileUploadWebSmokeGateEnabled()) {
            $this->markTestSkipped('FILE_UPLOAD_WEB_SMOKE_GATE=0 — skip 006 multipart serve gate (#2009)');
        }
        if (!$this->canBindLoopback()) {
            $this->markTestSkipped('Cannot bind loopback TCP');
        }
        if (!$this->commandExists('curl')) {
            $this->markTestSkipped('curl not available');
        }

        $repoRoot = dirname(__DIR__, 2);
        $docroot = $repoRoot.'/examples/006-FileUploadWeb';
        $uploadFile = $docroot.'/README.md';
        $this->assertDirectoryExists($docroot);
        $this->assertFileExists($uploadFile);

        $port = $this->findFreeTcpPort();
        $addr = '127.0.0.1:'.$port;
        $phpc = $repoRoot.'/phpc';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'serve', $addr, $docroot],
            $descriptorSpec,
            $pipes,
            $repoRoot,
            null,
            ['suppress_errors' => true]
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        try {
            $this->waitForTcpPort($port);
            $url = 'http://127.0.0.1:'.$port.'/example.php';

            $empty = $this->curlGet($url);
            $this->assertStringContainsString('No upload yet', $empty);

            $uploaded = $this->curlPostMultipart($url, 'doc', $uploadFile);
            $this->assertStringContainsString('Uploaded: README.md', $uploaded);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /**
     * 006-FileUploadWeb: native AOT link via phpc build --project (#1999).
     *
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function test006FileUploadWebAotLink(): void
    {
        if (!self::fileUploadWebAotLinkGateEnabled()) {
            $this->markTestSkipped('FILE_UPLOAD_WEB_AOT_LINK_GATE=0 — skip 006 project link gate (#1999)');
        }
        $project = $this->fileUploadWebProjectPath();
        $binary = $this->build006FileUploadWebProject($project);
        $this->assertFileExists($binary);
    }

    /**
     * @dataProvider provideExamples
     *
     * @group llvm
     * @group aot
     * @group aot-lint
     */
    public function testAotLint(string $examplePath): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $name = basename(dirname($examplePath));
        $this->runCli('compile.php', array_merge(self::vmExtraArgs($name), ['-l', $examplePath]), true);
    }

    /**
     * 003-MiniWebApp entry: AOT lint via direct-include bundle (issue #54, #585).
     *
     * @group miniwebapp
     * @group llvm
     * @group aot
     * @group aot-lint
     */
    public function testAotLintMiniWebAppPublicIndex(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $index = realpath(dirname(__DIR__, 2).'/examples/003-MiniWebApp/public/index.php');
        if (false === $index) {
            $this->markTestSkipped('examples/003-MiniWebApp/public/index.php missing (#246)');
        }
        $this->runCli('compile.php', ['-l', $index], true);
    }

    /**
     * phpc build --project on 003-MiniWebApp: AOT lint stage before native link (#624).
     *
     * @group miniwebapp
     * @group llvm
     * @group aot
     * @group aot-lint
     */
    public function test003MiniWebAppProjectAotLint(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $project = realpath(dirname(__DIR__, 2).'/examples/003-MiniWebApp');
        if (false === $project) {
            $this->markTestSkipped('examples/003-MiniWebApp missing (#246)');
        }
        $phpc = realpath(dirname(__DIR__, 2).'/phpc');
        $this->assertNotFalse($phpc);
        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', $project, '--dry-run'],
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            'phpc build --project 003-MiniWebApp --dry-run failed (#624): '
            .trim($stderr !== false ? $stderr : '(no stderr)')
        );
    }

    /**
     * Shipped 001-SimpleWeb: build AOT binary without compile-time `-q`, run twice with
     * different QUERY_STRING — catches regressions in runtime superglobal refresh for web binaries.
     *
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function testAotExecuteSimpleWebDualQuery(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $source = realpath(dirname(__DIR__, 2).'/examples/001-SimpleWeb/example.php');
        $this->assertNotFalse($source);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->compileAotBinaryNoQueryBaking($source, $repoRoot, $env);

        $envAlice = $env;
        $envAlice['QUERY_STRING'] = 'name=Alice';
        $envAlice['SCRIPT_NAME'] = '/example.php';
        $envAlice['REQUEST_URI'] = '/example.php?name=Alice';
        $outAlice = $this->runAotBinary($binary, $envAlice);
        $this->assertStringContainsString('<h1>Hello Alice</h1>', $outAlice);

        $envBob = $env;
        $envBob['QUERY_STRING'] = 'name=Bob';
        $envBob['SCRIPT_NAME'] = '/example.php';
        $envBob['REQUEST_URI'] = '/example.php?name=Bob';
        $outBob = $this->runAotBinary($binary, $envBob);
        $this->assertStringContainsString('<h1>Hello Bob</h1>', $outBob);

        @unlink($binary);
    }

    /**
     * Shipped 001-SimpleWeb: AOT binary with REQUEST_BODY — $_REQUEST POST path (issue #259).
     *
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function testAotExecuteSimpleWebPost(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $source = realpath(dirname(__DIR__, 2).'/examples/001-SimpleWeb/example.php');
        $this->assertNotFalse($source);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->compileAotBinaryNoQueryBaking($source, $repoRoot, $env);

        $envPost = $env;
        $envPost['REQUEST_METHOD'] = 'POST';
        $envPost['REQUEST_BODY'] = 'name=PostAot';
        $envPost['SCRIPT_NAME'] = '/example.php';
        $envPost['REQUEST_URI'] = '/example.php';
        $out = $this->runAotBinary($binary, $envPost);
        $this->assertStringContainsString('<h1>Hello PostAot</h1>', $out);

        @unlink($binary);
    }

    /**
     * Shipped 004-ApiJson: compile.php -o temp binary — json_encode + http_response_code(200) AOT smoke.
     *
     * @group llvm
     * @group aot
     * @group aot-link
     *
     * @see https://github.com/PurHur/php-compiler/issues/270
     */
    public function testAotExecuteSmoke004ApiJson(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $repoRoot = dirname(__DIR__, 2);
        $source = realpath($repoRoot.'/examples/004-ApiJson/example.php');
        $this->assertNotFalse($source);

        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->compileAotBinaryNoQueryBaking($source, $repoRoot, $env);
        $out = $this->runAotBinary($binary, $env);
        $this->assertStringContainsString('Content-Type: application/json', $out);
        $this->assertStringContainsString('Status: 200', $out);
        foreach (self::smokeNeedles('004-ApiJson') as $needle) {
            $this->assertStringContainsString($needle, $out);
        }

        @unlink($binary);
    }

    /**
     * Shipped 002-StaticWeb: compile.php -o temp binary, run once — AOT link + runtime smoke (no superglobals).
     *
     * @group llvm
     * @group aot
     * @group aot-link
     *
     * @see https://github.com/PurHur/php-compiler/issues/247
     */
    public function testAotExecuteSmoke002StaticWeb(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $repoRoot = dirname(__DIR__, 2);
        $source = realpath($repoRoot.'/examples/002-StaticWeb/example.php');
        $this->assertNotFalse($source);

        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->compileAotBinaryNoQueryBaking($source, $repoRoot, $env);
        $out = $this->runAotBinary($binary, $env);
        $this->assertStringContainsString('Hello World', $out);

        @unlink($binary);
    }

    /**
     * Shipped 002-StaticWeb: phpc build --project reads phpc.json entry/binary (issue #106).
     *
     * @group llvm
     * @group aot
     * @group aot-link
     */
    /**
     * phpc build --project bundles manifest includes with entry (issue #452).
     *
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function testPhpcBuildProjectWithIncludes(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $repoRoot = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir().'/phpc_build_inc_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/src', 0777, true));
        $this->assertTrue(mkdir($dir.'/.phpc/bin', 0777, true));
        try {
            file_put_contents($dir.'/src/helpers.php', "<?php\n\$greeting = 'Hi ';\n");
            file_put_contents($dir.'/entry.php', "<?php\necho \$greeting, 'there';\n");
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'entry.php',
                    'binary' => '.phpc/bin/app',
                    'includes' => ['src/helpers.php'],
                ], JSON_THROW_ON_ERROR)
            );

            $phpc = realpath($repoRoot.'/phpc');
            $this->assertNotFalse($phpc);
            $env = $this->llvmProcessEnv($repoRoot);
            $binaryPath = $dir.'/.phpc/bin/app';

            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open([$phpc, 'build', '--project', $dir], $descriptorSpec, $pipes, $repoRoot, $env);
            $this->assertIsResource($proc);
            fclose($pipes[0]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));
            $this->assertFileExists($binaryPath);

            $out = $this->runAotBinary($binaryPath, $env);
            $this->assertStringContainsString('Hi there', $out);
        } finally {
            $binaryPath = $dir.'/.phpc/bin/app';
            if (is_file($binaryPath)) {
                @unlink($binaryPath);
            }
            $this->removeTree($dir);
        }
    }

    public function testPhpcBuildProject002StaticWeb(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $repoRoot = dirname(__DIR__, 2);
        $exampleDir = realpath($repoRoot.'/examples/002-StaticWeb');
        $this->assertNotFalse($exampleDir);

        $phpc = realpath($repoRoot.'/phpc');
        $this->assertNotFalse($phpc);
        $env = $this->llvmProcessEnv($repoRoot);

        $binaryPath = $exampleDir.'/.phpc/bin/app';
        if (is_file($binaryPath)) {
            unlink($binaryPath);
        }
        $binDir = dirname($binaryPath);
        if (!is_dir($binDir)) {
            mkdir($binDir, 0777, true);
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$phpc, 'build', '--project', $exampleDir], $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));
        $this->assertFileExists($binaryPath);
        $this->assertTrue(is_executable($binaryPath));

        $out = $this->runAotBinary($binaryPath, $env);
        $this->assertStringContainsString('Hello World', $out);

        @unlink($binaryPath);
    }

    /**
     * Shipped 002-StaticWeb: ./phpc build then execute — smoke for unified CLI argv/env forwarding.
     *
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function testPhpcBuildSmoke002StaticWeb(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $repoRoot = dirname(__DIR__, 2);
        $source = realpath($repoRoot.'/examples/002-StaticWeb/example.php');
        $this->assertNotFalse($source);

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_phpc_build_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $phpc = realpath($repoRoot.'/phpc');
        $this->assertNotFalse($phpc);
        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->phpcBuildBinary($phpc, $outfile, $source, $repoRoot, $env);

        $out = $this->runAotBinary($binary, $env);
        $this->assertStringContainsString('Hello World', $out);

        @unlink($binary);
    }

    /**
     * @param array<string, string> $env
     */
    private function phpcBuildBinary(string $phpc, string $outfile, string $source, string $repoRoot, array $env): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$phpc, 'build', '-o', $outfile, $source], $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'phpc build failed for '.$source
        );
        $this->assertFileExists($outfile, trim($stderr !== false ? $stderr : ''));
        $this->assertTrue(is_executable($outfile));

        return $outfile;
    }

    /**
     * @param array<string, string> $env
     */
    private function compileAotBinaryNoQueryBaking(string $source, string $repoRoot, array $env): string
    {
        $outfile = tempnam(sys_get_temp_dir(), 'phpc_ex_gate_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $bin = realpath($repoRoot.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $cmd = array_merge(
            self::llvmEnvPrefix(),
            self::phpCommand(),
            [$bin, '-o', $outfile, $source]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php failed for '.$source
        );
        $this->assertFileExists($outfile, trim($stderr !== false ? $stderr : ''));
        $this->assertTrue(is_executable($outfile));

        return $outfile;
    }

    /**
     * @param array<string, string> $env
     */
    private function runAotBinary(string $binary, array $env): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $run = proc_open([$binary], $descriptorSpec, $pipes, null, $env);
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

    /**
     * @param list<string> $lintArgs arguments after bin/lint.php
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runLint(array $lintArgs): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [$repoRoot.'/bin/lint.php'], $lintArgs);

        return $this->runLintCommand($cmd, $repoRoot);
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runLintCommand(array $cmd, string $cwd): array
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

    /**
     * @param list<string> $argvArgs arguments after the bin script path
     */
    private function runCli(string $binScript, array $argvArgs, bool $llvm = false): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $bin = realpath($repoRoot.'/bin/'.$binScript);
        $this->assertNotFalse($bin);
        $cmd = array_merge(
            $llvm ? self::llvmEnvPrefix() : [],
            self::phpCommand(),
            array_merge([$bin], $argvArgs)
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $llvm ? $this->llvmProcessEnv($repoRoot) : null;
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim(($stderr !== false ? $stderr : '')."\n".($stdout !== false ? $stdout : ''))
        );

        return $stdout !== false ? $stdout : '';
    }

    /**
     * @return list<string>
     */
    private static function sessionsWebSmokeGateEnabled(): bool
    {
        $gate = getenv('SESSIONS_WEB_SMOKE_GATE');

        return false === $gate || '0' !== $gate;
    }

    private static function fileUploadWebSmokeGateEnabled(): bool
    {
        $gate = getenv('FILE_UPLOAD_WEB_SMOKE_GATE');

        return false === $gate || '0' !== $gate;
    }

    private static function fileUploadWebAotLinkGateEnabled(): bool
    {
        $gate = getenv('FILE_UPLOAD_WEB_AOT_LINK_GATE');

        return false !== $gate && '' !== $gate && '1' === $gate;
    }

    private function curlGet(string $url): string
    {
        $cmd = ['curl', '-sS', '--connect-timeout', '5', '--max-time', '15', $url];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? $stdout : '';
    }

    private function curlPostMultipart(string $url, string $field, string $filePath): string
    {
        $cmd = [
            'curl', '-sS', '--connect-timeout', '5', '--max-time', '15',
            '-F', $field.'=@'.$filePath, $url,
        ];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? $stdout : '';
    }

    private function canBindLoopback(): bool
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = [$repoRoot.'/script/php-local.sh', $repoRoot.'/script/can-bind-loopback.php'];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($proc);
    }

    private function commandExists(string $name): bool
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', '-c', 'command -v '.escapeshellarg($name)], $descriptorSpec, $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($proc);
    }

    private function findFreeTcpPort(): int
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = [$repoRoot.'/script/php-local.sh', '-r', <<<'PHP'
$s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $s) {
    fwrite(STDERR, "find port: {$errstr}\n");
    exit(1);
}
$name = stream_socket_get_name($s, false);
fclose($s);
if (!is_string($name) || !preg_match('#:(\d+)$#', $name, $m)) {
    fwrite(STDERR, "find port: invalid name\n");
    exit(1);
}
echo $m[1];
PHP];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit);
        $port = (int) trim($stdout !== false ? $stdout : '');
        $this->assertGreaterThan(0, $port);

        return $port;
    }

    private function waitForTcpPort(int $port, int $timeoutSeconds = 10): void
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($fp)) {
                fclose($fp);

                return;
            }
            usleep(50_000);
        }
        $this->fail('TCP port '.$port.' did not become ready');
    }

    private function curlGetWithJar(string $url, string $jar): string
    {
        $cmd = [
            'curl', '-sS', '-b', $jar, '-c', $jar,
            '--connect-timeout', '5', '--max-time', '15', $url,
        ];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? $stdout : '';
    }

    private function curlPostWithJar(string $url, string $jar, string $postBody): string
    {
        $cmd = [
            'curl', '-sS', '-b', $jar, '-c', $jar,
            '-X', 'POST', '-d', $postBody,
            '-o', '/dev/null', '-w', '%{http_code}',
            '--connect-timeout', '5', '--max-time', '15', $url,
        ];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));

        return trim($stdout !== false ? $stdout : '');
    }

    private static function vmExtraArgs(string $exampleName): array
    {
        if ('001-SimpleWeb' === $exampleName) {
            return ['-q', 'name=Example'];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function smokeNeedles(string $exampleName): array
    {
        return match ($exampleName) {
            '000-HelloWorld' => ['Hello World'],
            '001-SimpleWeb' => ['Hello Example'],
            '002-StaticWeb' => ['Hello World'],
            '004-ApiJson' => ['"ok":true', 'php-compiler'],
            '005-SessionsWeb' => ['SessionsWeb', 'No flash message yet'],
            '006-FileUploadWeb' => ['FileUploadWeb', 'No upload yet'],
            default => ['Hello'],
        };
    }

    /**
     * @return array<string, string>
     */
    private function llvmProcessEnv(string $repoRoot): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        return $env;
    }

    /**
     * @return list<string>
     */
    private static function phpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            return preg_split('/\s+/', $phpEnv) ?: [PHP_BINARY];
        }
        $cmd = [PHP_BINARY];
        $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
        if (is_dir($extDir)) {
            foreach (['tokenizer', 'mbstring', 'dom', 'xml', 'xmlwriter', 'ffi', 'posix', 'phar'] as $ext) {
                $so = $extDir.'/'.$ext.'.so';
                if (is_file($so)) {
                    $cmd[] = '-d';
                    $cmd[] = 'extension='.$so;
                }
            }
        }
        $cmd[] = '-d';
        $cmd[] = 'display_errors=0';
        $cmd[] = '-d';
        $cmd[] = 'error_reporting=0';

        return $cmd;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private static function sessionsWebAotLinkGateEnabled(): bool
    {
        $gate = getenv('SESSIONS_WEB_AOT_LINK_GATE');

        return false === $gate || '0' !== $gate;
    }

    private static function miniWebAppAotLinkGateEnabled(): bool
    {
        $gate = getenv('MINIWEBAPP_AOT_LINK_GATE');

        return false === $gate || '0' !== $gate;
    }

    private static function miniWebAppAotExecuteGateEnabled(): bool
    {
        $gate = getenv('MINIWEBAPP_AOT_EXECUTE_GATE');

        return false === $gate || '' === $gate || '1' === $gate;
    }

    private function miniWebAppProjectPath(): string
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run make docker-build-22 or script/install-llvm9.sh from the repository root.'
            );
        }
        $project = realpath(dirname(__DIR__, 2).'/examples/003-MiniWebApp');
        if (false === $project) {
            $this->markTestSkipped('examples/003-MiniWebApp missing (#246)');
        }

        return $project;
    }

    private function sessionsWebProjectPath(): string
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run make docker-build-22 or script/install-llvm9.sh from the repository root.'
            );
        }
        $project = realpath(dirname(__DIR__, 2).'/examples/005-SessionsWeb');
        if (false === $project) {
            $this->markTestSkipped('examples/005-SessionsWeb missing (#1881)');
        }

        return $project;
    }

    private function build003MiniWebAppProject(string $project): string
    {
        $phpc = realpath(dirname(__DIR__, 2).'/phpc');
        $this->assertNotFalse($phpc);
        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', $project],
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stderrText = trim($stderr !== false ? $stderr : '');
        $this->assertSame(
            0,
            $exit,
            'phpc build --project 003-MiniWebApp failed (#754 link): '.$stderrText
        );

        return $project.'/.phpc/bin/app';
    }

    private function build005SessionsWebProject(string $project): string
    {
        $phpc = realpath(dirname(__DIR__, 2).'/phpc');
        $this->assertNotFalse($phpc);
        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', $project],
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stderrText = trim($stderr !== false ? $stderr : '');
        $this->assertSame(
            0,
            $exit,
            'phpc build --project 005-SessionsWeb failed (#1946 link): '.$stderrText
        );

        return $project.'/.phpc/bin/app';
    }

    private function fileUploadWebProjectPath(): string
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run make docker-build-22 or script/install-llvm9.sh from the repository root.'
            );
        }
        $project = realpath(dirname(__DIR__, 2).'/examples/006-FileUploadWeb');
        if (false === $project) {
            $this->markTestSkipped('examples/006-FileUploadWeb missing (#1999)');
        }

        return $project;
    }

    private function build006FileUploadWebProject(string $project): string
    {
        $phpc = realpath(dirname(__DIR__, 2).'/phpc');
        $this->assertNotFalse($phpc);
        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', $project],
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stderrText = trim($stderr !== false ? $stderr : '');
        $this->assertSame(
            0,
            $exit,
            'phpc build --project 006-FileUploadWeb failed (#1999 link): '.$stderrText
        );

        return $project.'/.phpc/bin/app';
    }

    private static function isLlvmReady(): bool
    {
        if (null !== self::$llvmReady) {
            return self::$llvmReady;
        }
        self::$llvmReady = LlvmToolchain::isReady(dirname(__DIR__, 2));

        return self::$llvmReady;
    }

    /**
     * @return list<string>
     */
    private static function llvmEnvPrefix(): array
    {
        return LlvmToolchain::envPrefix(dirname(__DIR__, 2));
    }
}
