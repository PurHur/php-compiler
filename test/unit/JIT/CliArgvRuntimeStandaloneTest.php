<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\CliArgvRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issues #5407, #6341, #20904: AOT must define CLI argv helpers without phpc_cli_argv.c.
 * Thin + embed both use honest refresh (no void stub).
 *
 * @group aot-lint
 */
final class CliArgvRuntimeStandaloneTest extends TestCase
{
    /** @var list<string> */
    private const ABI = [
        '__phpc_cli_store_argv',
        '__phpc_cli_argc',
        '__phpc_cli_argv_cstr',
        '__phpc_cli_str_eq',
        '__phpc_cli_refresh_argv_global',
    ];

    public function testSourceHasNoThinVoidRefreshFork(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/CliArgvRuntime.php');
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('ensureUserScriptMainStubs', $source);
        $this->assertStringNotContainsString('cli_refresh_stub', $source);
        $this->assertStringContainsString('__hashtable__alloc', $source);
        $this->assertStringContainsString('implementRefreshArgvGlobal', $source);
    }

    public function testEnsureLinkedDefinesCliArgvForEmbed(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_IMPORT);
        CliArgvRuntime::ensureLinked($ctx);
        $this->assertAbiBodies($ctx);
    }

    public function testThinUserScriptDefinesHonestRefresh(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            $this->assertAbiBodies($ctx);
            $refresh = $ctx->lookupFunction('__phpc_cli_refresh_argv_global');
            $this->assertGreaterThan(1, $refresh->countBasicBlocks(), 'refresh must be honest bridge, not void stub');
        } finally {
            if (false === $prev || '' === (string) $prev) {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
                unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
            } else {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT='.$prev);
                $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = $prev;
                $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = $prev;
            }
        }
    }

    private function assertAbiBodies(Context $ctx): void
    {
        foreach (self::ABI as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
