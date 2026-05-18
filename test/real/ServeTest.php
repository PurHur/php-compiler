<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for bin/serve.php (issue #152, #150).
 */
final class ServeTest extends TestCase
{
  private string $repoRoot;

  /** @var list<string> */
  private array $phpCmd = [];

  protected function setUp(): void
  {
    $this->repoRoot = dirname(__DIR__, 2);
    $this->phpCmd = self::phpCommand();
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

  /**
   * @param array<string, string> $extraEnv
   */
  private function httpGet(string $docroot, string $path, array $extraEnv = []): string
  {
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
    fwrite($conn, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    $response = stream_get_contents($conn);
    fclose($conn);

    proc_terminate($proc);
    proc_close($proc);

    return $response !== false ? $response : '';
  }

  /**
   * @param array<string, string> $files relative path => contents
   */
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
