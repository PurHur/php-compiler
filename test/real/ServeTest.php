<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for bin/serve.php (issues #151, #152, #150).
 *
 * @group serve
 */
final class ServeTest extends TestCase
{
  private string $repoRoot;

  /** @var list<string> */
  private array $phpCmd = [];

  protected function setUp(): void
  {
    if (false !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS') && '' !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS')) {
      $this->markTestSkipped('PHP_COMPILER_SKIP_SERVE_TESTS is set');
    }
    $this->repoRoot = dirname(__DIR__, 2);
    $this->phpCmd = self::phpCommand();
  }

  public function testServeFromProjectRootUsesPublicDocroot(): void
  {
    $project = $this->makeDocroot([
      'phpc.json' => json_encode([
        'entry' => 'public/index.php',
        'binary' => '.phpc/bin/app',
        'public' => 'public',
      ]),
      'public/index.php' => '<?php echo "from-public";',
      'src/secret.txt' => 'leaked',
    ]);
    $response = $this->httpGet($project, '/index.php');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('from-public', $response);
    $this->assertStringNotContainsString('leaked', $response);

    $blocked = $this->httpGet($project, '/../src/secret.txt');
    $this->assertStringContainsString('HTTP/1.1 403', $blocked);
    $this->assertStringNotContainsString('leaked', $blocked);
  }

  public function testServeFromProjectRootSetsDocumentRootToPublic(): void
  {
    $project = $this->makeDocroot([
      'phpc.json' => json_encode([
        'entry' => 'public/index.php',
        'binary' => '.phpc/bin/app',
        'public' => 'public',
      ]),
      'public/docroot.php' => <<<'PHP'
<?php
echo $_SERVER['DOCUMENT_ROOT'];
PHP,
    ]);
    $public = realpath($project.'/public');
    $this->assertNotFalse($public);
    $response = $this->httpGet($project, '/docroot.php');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString($public, $this->responseBody($response));
  }

  public function testServes001SimpleWebExample(): void
  {
    $docroot = $this->repoRoot.'/examples/001-SimpleWeb';
    $this->assertDirectoryExists($docroot);
    $response = $this->httpGet($docroot, '/example.php?name=Dev');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('Content-Type: text/html', $response);
    $this->assertStringContainsString('Hello', $response);
    $this->assertStringContainsString('Dev', $response);
  }

  public function testServesIndexPhpForRootPath(): void
  {
    $docroot = $this->makeDocroot([
      'index.php' => '<?php echo "from-index";',
    ]);
    $response = $this->httpGet($docroot, '/');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('from-index', $response);
  }

  public function testExamplePhpFallbackWhenNoIndex(): void
  {
    $docroot = $this->makeDocroot([
      'example.php' => '<?php echo "from-example";',
    ]);
    $response = $this->httpGet($docroot, '/');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('from-example', $response);
  }

  public function testPrefersIndexPhpOverExamplePhp(): void
  {
    $docroot = $this->makeDocroot([
      'index.php' => '<?php echo "index-wins";',
      'example.php' => '<?php echo "example-loses";',
    ]);
    $response = $this->httpGet($docroot, '/');
    $this->assertStringContainsString('index-wins', $response);
    $this->assertStringNotContainsString('example-loses', $response);
  }

  public function testUncaughtExceptionReturns500WithoutLeak(): void
  {
    $docroot = $this->makeDocroot(['error.php' => '<?php no_such_func();']);
    $response = $this->httpGet($docroot, '/error.php');
    $this->assertStringContainsString('HTTP/1.1 500', $response);
    $this->assertStringContainsString('Internal Server Error', $response);
    $this->assertStringNotContainsString('no_such_func', $response);
    $this->assertStringNotContainsString('LogicException', $response);
  }

  public function testUncaughtExceptionDebugModeIncludesClass(): void
  {
    $docroot = $this->makeDocroot(['error.php' => '<?php no_such_func();']);
    $response = $this->httpGet($docroot, '/error.php', ['PHP_COMPILER_DEBUG' => '1']);
    $this->assertStringContainsString('HTTP/1.1 500', $response);
    $this->assertStringContainsString('LogicException', $response);
    $this->assertStringContainsString('no_such_func', $response);
  }

  public function testServesStaticCssFromDocroot(): void
  {
    $docroot = $this->makeDocroot(['style.css' => 'body { color: navy; }']);
    $response = $this->httpGet($docroot, '/style.css');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('Content-Type: text/css', $response);
    $this->assertStringContainsString('body { color: navy; }', $response);
  }

  public function testRejectsPathTraversal(): void
  {
    $docroot = $this->makeDocroot(['secret.txt' => 'hidden']);
    $response = $this->httpGet($docroot, '/../secret.txt');
    $this->assertStringContainsString('HTTP/1.1 403', $response);
    $this->assertStringNotContainsString('hidden', $response);
  }

  public function testHttpResponseCodeSetsStatusLine(): void
  {
    $docroot = $this->makeDocroot([
      'notfound.php' => <<<'PHP'
<?php
http_response_code(404);
echo 'missing';
PHP,
    ]);
    $response = $this->httpGet($docroot, '/notfound.php');
    $this->assertStringContainsString('HTTP/1.1 404', $response);
    $this->assertStringContainsString('missing', $response);
  }

  public function testHttpResponseCode405SetsStatusLine(): void
  {
    $docroot = $this->makeDocroot([
      'method.php' => <<<'PHP'
<?php
http_response_code(405);
echo 'Nope';
PHP,
    ]);
    $response = $this->httpGet($docroot, '/method.php');
    $this->assertStringContainsString('HTTP/1.1 405', $response);
    $this->assertStringContainsString('Method Not Allowed', $response);
    $this->assertStringContainsString('Nope', $response);
  }

  public function testHeaderLocationRedirect302(): void
  {
    $docroot = $this->makeDocroot([
      'redirect.php' => <<<'PHP'
<?php
header('Location: /done');
http_response_code(302);
PHP,
    ]);
    $response = $this->httpGet($docroot, '/redirect.php');
    $this->assertStringContainsString('HTTP/1.1 302', $response);
    $this->assertStringContainsString('Location: /done', $response);
  }

  public function testPopulatesHttpServerHeaders(): void
  {
    $docroot = $this->makeDocroot([
      'headers.php' => <<<'PHP'
<?php
echo $_SERVER['HTTP_HOST'], '|', $_SERVER['HTTP_X_CUSTOM'];
PHP,
    ]);
    $response = $this->httpGet($docroot, '/headers.php', [], [
      'Host: example.test',
      'X-Custom: 1',
    ]);
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('example.test|1', $response);
  }

  public function testPopulatesServerProtocolFromRequestLine(): void
  {
    $docroot = $this->makeDocroot([
      'proto.php' => <<<'PHP'
<?php
echo 'P:', $_SERVER['SERVER_PROTOCOL'];
PHP,
    ]);
    $response = $this->httpGet($docroot, '/proto.php');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('P:HTTP/1.1', $response);
  }

  public function testPopulatesServerProtocolHttp10WhenRequestLineIs10(): void
  {
    $docroot = $this->makeDocroot([
      'proto.php' => <<<'PHP'
<?php
echo 'P:', $_SERVER['SERVER_PROTOCOL'];
PHP,
    ]);
    $response = $this->httpGet($docroot, '/proto.php', [], [], 'HTTP/1.0');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('P:HTTP/1.0', $response);
  }

  public function testPopulatesDocumentRoot(): void
  {
    $docroot = $this->makeDocroot([
      'docroot.php' => <<<'PHP'
<?php
echo $_SERVER['DOCUMENT_ROOT'];
PHP,
    ]);
    $resolved = realpath($docroot);
    $this->assertNotFalse($resolved);
    $response = $this->httpGet($docroot, '/docroot.php');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString($resolved, $response);
  }

  public function testPopulatesRemoteAddrForLoopback(): void
  {
    $docroot = $this->makeDocroot([
      'remote.php' => <<<'PHP'
<?php
echo $_SERVER['REMOTE_ADDR'], '|', $_SERVER['REMOTE_PORT'];
PHP,
    ]);
    $response = $this->httpGet($docroot, '/remote.php');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertMatchesRegularExpression('#127\.0\.0\.1\|\d+#', $this->responseBody($response));
  }

  public function testPopulatesScriptFilename(): void
  {
    $docroot = $this->makeDocroot([
      'script.php' => <<<'PHP'
<?php
echo $_SERVER['SCRIPT_FILENAME'];
PHP,
    ]);
    $script = realpath($docroot.'/script.php');
    $this->assertNotFalse($script);
    $response = $this->httpGet($docroot, '/script.php');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString($script, $response);
  }

  public function testPopulatesCookieFromRequestHeader(): void
  {
    $docroot = $this->makeDocroot([
      'cookie.php' => <<<'PHP'
<?php
echo $_COOKIE['theme'];
PHP,
    ]);
    $response = $this->httpGet($docroot, '/cookie.php', [], [
      'Cookie: theme=dark',
    ]);
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('dark', $response);
  }

  public function testSetcookieRoundTripOnSecondRequest(): void
  {
    $docroot = $this->makeDocroot([
      'set.php' => <<<'PHP'
<?php
header('Content-Type: text/plain; charset=UTF-8');
setcookie('sid', 'sess42', 0, '/');
echo 'set';
PHP,
      'get.php' => <<<'PHP'
<?php
header('Content-Type: text/plain; charset=UTF-8');
echo $_COOKIE['sid'] ?? '';
PHP,
    ]);
    $setResponse = $this->httpGet($docroot, '/set.php');
    $this->assertStringContainsString('HTTP/1.1 200', $setResponse);
    $this->assertStringContainsString('Set-Cookie: sid=sess42', $setResponse);
    $this->assertStringContainsString('set', $this->responseBody($setResponse));

    $getResponse = $this->httpGet($docroot, '/get.php', [], [
      'Cookie: sid=sess42',
    ]);
    $this->assertStringContainsString('HTTP/1.1 200', $getResponse);
    $this->assertStringContainsString('sess42', $this->responseBody($getResponse));
  }

  public function testPopulatesContentLengthOnPost(): void
  {
    $body = 'abcdefghijkl';
    $docroot = $this->makeDocroot([
      'length.php' => <<<'PHP'
<?php
header('Content-Type: text/plain; charset=UTF-8');
echo $_SERVER['CONTENT_LENGTH'];
PHP,
    ]);
    $response = $this->httpPost($docroot, '/length.php', $body);
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('12', $response);
  }

  public function testPostFormUrlencoded(): void
  {
    $docroot = $this->makeDocroot([
      'form.php' => <<<'PHP'
<?php
header('Content-Type: text/plain; charset=UTF-8');
echo 'name=', $_POST['name'];
PHP,
    ]);
    $response = $this->httpPost($docroot, '/form.php', 'name=Alice');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('name=Alice', $this->responseBody($response));
  }

  public function testPostFormUrlencodedChunkedTransferEncoding(): void
  {
    $docroot = $this->makeDocroot([
      'form.php' => <<<'PHP'
<?php
header('Content-Type: text/plain; charset=UTF-8');
echo 'name=', $_POST['name'];
PHP,
    ]);
    $response = $this->httpPostChunked($docroot, '/form.php', 'name=Chunk');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('name=Chunk', $this->responseBody($response));
  }

  public function testPutJsonBody(): void
  {
    $docroot = $this->makeDocroot([
      'api.php' => <<<'PHP'
<?php
header('Content-Type: text/plain; charset=UTF-8');
echo file_get_contents('php://input');
PHP,
    ]);
    $body = '{"id":7}';
    $response = $this->httpRequest(
      $docroot,
      'PUT',
      '/api.php',
      $body,
      ['Content-Type: application/json']
    );
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('{"id":7}', $this->responseBody($response));
  }

  public function testPutFormUrlencoded(): void
  {
    $docroot = $this->makeDocroot([
      'form.php' => <<<'PHP'
<?php
header('Content-Type: text/plain; charset=UTF-8');
echo 'name=', $_POST['name'];
PHP,
    ]);
    $response = $this->httpRequest(
      $docroot,
      'PUT',
      '/form.php',
      'name=Patch',
      ['Content-Type: application/x-www-form-urlencoded']
    );
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('name=Patch', $this->responseBody($response));
  }

  public function testPathInfoFrontControllerDispatch(): void
  {
    $docroot = $this->makeDocroot([
      'index.php' => <<<'PHP'
<?php
header('Content-Type: text/plain; charset=UTF-8');
$path = $_SERVER['PATH_INFO'] ?? '/';
echo 'SCRIPT_NAME=', $_SERVER['SCRIPT_NAME'], "\n";
echo 'PATH_INFO=', $path, "\n";
if ($path === '/hello') {
    echo 'hi';
}
PHP,
    ]);
    $response = $this->httpGet($docroot, '/index.php/hello');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $body = $this->responseBody($response);
    $this->assertStringContainsString('SCRIPT_NAME=/index.php', $body);
    $this->assertStringContainsString('PATH_INFO=/hello', $body);
    $this->assertStringContainsString('hi', $body);
  }

  public function testPathInfoMissingScriptReturns404(): void
  {
    $docroot = $this->makeDocroot([
      'index.php' => '<?php echo "ok";',
    ]);
    $response = $this->httpGet($docroot, '/missing.php/foo');
    $this->assertStringContainsString('HTTP/1.1 404', $response);
    $this->assertStringContainsString('Not Found', $this->responseBody($response));
  }

  /**
   * North-star example: PATH_INFO routes + deprecated ?route= (issues #489, #470).
   *
   * @group miniwebapp
   */
  public function testServes003MiniWebAppPathInfoRoutes(): void
  {
    $project = $this->repoRoot.'/examples/003-MiniWebApp';
    if (!is_file($project.'/public/index.php')) {
      $this->markTestSkipped('examples/003-MiniWebApp missing (#246)');
    }
    if (!$this->miniWebAppLintGreen($project)) {
      $this->markTestSkipped('003-MiniWebApp lint not green (#539)');
    }

    $home = $this->httpGet($project, '/index.php');
    $this->assertStringContainsString('HTTP/1.1 200', $home);
    $this->assertStringContainsString('MiniWebApp', $this->responseBody($home));

    $hello = $this->httpGet($project, '/index.php/hello?name=Dev');
    $this->assertStringContainsString('HTTP/1.1 200', $hello);
    $body = $this->responseBody($hello);
    $this->assertStringContainsString('Hello Dev', $body);

    $api = $this->httpGet($project, '/index.php/api/status');
    $this->assertStringContainsString('HTTP/1.1 200', $api);
    $this->assertStringContainsString('"ok":true', $this->responseBody($api));

    $legacy = $this->httpGet($project, '/index.php?route=home');
    $this->assertStringContainsString('HTTP/1.1 200', $legacy);
    $this->assertStringContainsString('MiniWebApp', $this->responseBody($legacy));
  }

  /**
   * @group miniwebapp
   */
  public function testServes003MiniWebAppContactPost(): void
  {
    $project = $this->repoRoot.'/examples/003-MiniWebApp';
    if (!is_file($project.'/public/index.php')) {
      $this->markTestSkipped('examples/003-MiniWebApp missing (#246)');
    }
    if (!$this->miniWebAppLintGreen($project)) {
      $this->markTestSkipped('003-MiniWebApp lint not green (#539)');
    }

    $response = $this->httpPost($project, '/index.php/contact', 'name=PostDev');
    $this->assertStringContainsString('HTTP/1.1 200', $response);
    $this->assertStringContainsString('Thank you, PostDev', $this->responseBody($response));
  }

  private function miniWebAppLintGreen(string $projectDir): bool
  {
    $descriptorSpec = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $cmd = array_merge($this->phpCmd, [$this->repoRoot.'/bin/lint.php', '--all', $projectDir]);
    $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot);
    if (!is_resource($proc)) {
      return false;
    }
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return 0 === proc_close($proc);
  }

  /**
   * @param array<string, string> $extraEnv
   * @param list<string>          $extraRequestHeaders
   */
  private function httpGet(
      string $docroot,
      string $path,
      array $extraEnv = [],
      array $extraRequestHeaders = [],
      string $requestProtocol = 'HTTP/1.1'
  ): string {
    $port = $this->findFreePort();
    $addr = "127.0.0.1:{$port}";
    $env = array_merge($this->baseEnv(), $extraEnv);
    $descriptorSpec = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $cmd = array_merge($this->phpCmd, [$this->repoRoot.'/bin/serve.php', $addr, $docroot]);
    $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
    $this->assertIsResource($proc);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = microtime(true) + 5.0;
    $ready = false;
    while (microtime(true) < $deadline) {
      $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
      if (false !== $conn) {
        $ready = true;
        fclose($conn);
        break;
      }
      usleep(50_000);
    }
    $this->assertTrue($ready, 'serve did not become ready');

    $conn = fsockopen('127.0.0.1', $port);
    $this->assertIsResource($conn);
    $requestHeaders = array_merge(['Host: 127.0.0.1', 'Connection: close'], $extraRequestHeaders);
    $headerBlock = implode("\r\n", $requestHeaders);
    fwrite($conn, "GET {$path} {$requestProtocol}\r\n{$headerBlock}\r\n\r\n");
    $response = stream_get_contents($conn);
    fclose($conn);

    proc_terminate($proc);
    proc_close($proc);

    return $response !== false ? $response : '';
  }

  private function httpPost(string $docroot, string $path, string $body, array $extraEnv = []): string
  {
    return $this->httpRequest(
      $docroot,
      'POST',
      $path,
      $body,
      ['Content-Type: application/x-www-form-urlencoded'],
      $extraEnv
    );
  }

  private function httpPostChunked(string $docroot, string $path, string $body, array $extraEnv = []): string
  {
    $chunked = dechex(strlen($body))."\r\n".$body."\r\n0\r\n\r\n";

    return $this->httpRequest(
      $docroot,
      'POST',
      $path,
      $chunked,
      [
        'Content-Type: application/x-www-form-urlencoded',
        'Transfer-Encoding: chunked',
      ],
      $extraEnv,
      useContentLength: false
    );
  }

  /**
   * @param list<string>         $extraRequestHeaders
   * @param array<string,string> $extraEnv
   */
  private function httpRequest(
      string $docroot,
      string $method,
      string $path,
      string $body,
      array $extraRequestHeaders = [],
      array $extraEnv = [],
      bool $useContentLength = true
  ): string {
    $port = $this->findFreePort();
    $addr = "127.0.0.1:{$port}";
    $env = array_merge($this->baseEnv(), $extraEnv);
    $descriptorSpec = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $cmd = array_merge($this->phpCmd, [$this->repoRoot.'/bin/serve.php', $addr, $docroot]);
    $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
    $this->assertIsResource($proc);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = microtime(true) + 5.0;
    $ready = false;
    while (microtime(true) < $deadline) {
      $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
      if (false !== $conn) {
        $ready = true;
        fclose($conn);
        break;
      }
      usleep(50_000);
    }
    $this->assertTrue($ready, 'serve did not become ready');

    $conn = fsockopen('127.0.0.1', $port);
    $this->assertIsResource($conn);
    $headers = ['Host: 127.0.0.1', 'Connection: close'];
    if ($useContentLength) {
      $headers[] = 'Content-Length: '.strlen($body);
    }
    $headers = array_merge($headers, $extraRequestHeaders);
    $headerBlock = implode("\r\n", $headers);
    fwrite(
      $conn,
      "{$method} {$path} HTTP/1.1\r\n"
      ."{$headerBlock}\r\n\r\n"
      .$body
    );
    $response = stream_get_contents($conn);
    fclose($conn);

    proc_terminate($proc);
    proc_close($proc);

    return $response !== false ? $response : '';
  }

  private function responseBody(string $response): string
  {
    $parts = preg_split("/\r\n\r\n|\n\n/", $response, 2);

    return $parts[1] ?? '';
  }

  private function makeDocroot(array $files): string
  {
    $dir = sys_get_temp_dir().'/phpc_serve_'.bin2hex(random_bytes(4));
    $this->assertTrue(mkdir($dir));
    foreach ($files as $name => $contents) {
      $path = $dir.'/'.$name;
      $parent = dirname($path);
      if (!is_dir($parent)) {
        mkdir($parent, 0777, true);
      }
      file_put_contents($path, $contents);
    }

    return $dir;
  }

  private function findFreePort(): int
  {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $this->assertNotFalse($server, $errstr);
    $name = stream_socket_get_name($server, false);
    fclose($server);
    $this->assertIsString($name);
    $this->assertMatchesRegularExpression('#:(\d+)$#', $name, $name);

    return (int) preg_replace('#^.*:#', '', $name);
  }

  /**
   * @return array<string, string>
   */
  private function baseEnv(): array
  {
    $env = [];
    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
      if (is_string($key) && is_string($value)) {
        $env[$key] = $value;
      }
    }

    return $env;
  }

  /**
   * @return list<string>
   */
  private static function phpCommand(): array
  {
    $phpEnv = getenv('PHP_COMPILER_PHP');
    if (false !== $phpEnv && '' !== $phpEnv) {
      $cmd = preg_split('/\s+/', $phpEnv);
    } else {
      $cmd = [PHP_BINARY];
    }
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

    return $cmd;
  }
}
