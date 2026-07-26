<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\session\SessionFileStorage;
use PHPCompiler\ext\standard\SessionStorageJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * SessionStorage NestedJIT via JitVmHelperLink::ensureCompiled (#23284 / peer #23211).
 */
final class SessionStorageRuntimeShrinkTest extends TestCase
{
    public function testBuiltinSessionStorageRuntimeIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitSessionStorageKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/SessionStorageRuntime.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionStorageRuntime.php');
        $this->assertStringContainsString('JitSessionStorageKernel', $orchestrator);
        $this->assertStringContainsString('JitSessionStorageKernel::ensureLinked', $orchestrator);
        $this->assertStringContainsString('JitSessionStorageKernel::ensureStandaloneBodies', $orchestrator);
        $this->assertStringContainsString('JitSessionStorageKernel::implement', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $orchestrator);
        $this->assertStringNotContainsString('implementLoadBridge', $orchestrator);
        $this->assertStringNotContainsString('phpc_session_load_from_disk', $orchestrator);
        $this->assertStringNotContainsString('SessionStorageStandaloneLlvm', $orchestrator);
        $this->assertLessThan(50, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSessionStorageKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitSessionStorageKernel', $source);
        $this->assertStringContainsString('phpc_session_load_from_disk', $source);
        $this->assertStringContainsString('SessionStorageJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(1400, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSessionStorageKernel.php', $spine);
        $this->assertStringContainsString('SessionStorageRuntime.php', $spine);
        $kernelPos = strpos($spine, 'JitSessionStorageKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/SessionStorageRuntime.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }

    public function testSessionStorageJitHelperRoundTrip(): void
    {
        $dir = sys_get_temp_dir().'/phpc_ss_helper_'.getmypid();
        @mkdir($dir, 0700, true);
        putenv('PHP_COMPILER_SESSION_DIR='.$dir);

        $sessionId = 'abc123';
        $ht = new HashTable();
        $key = new Variable(Variable::TYPE_STRING);
        $key->string('user');
        $val = new Variable(Variable::TYPE_STRING);
        $val->string('alice');
        $ht->add('user', $val);

        SessionStorageJitHelper::saveToDisk($sessionId, $ht);

        $loaded = new HashTable();
        SessionStorageJitHelper::loadFromDisk($sessionId, $loaded);

        $found = null;
        foreach ($loaded->iterateKeyed(true) as [$k, $v]) {
            if (Variable::TYPE_STRING === $k->type && 'user' === $k->toString()) {
                $found = $v;
                break;
            }
        }
        $this->assertInstanceOf(Variable::class, $found);
        $this->assertSame('alice', $found->resolveIndirect()->toString());

        SessionStorageJitHelper::unlinkFile($sessionId);
        $this->assertFileDoesNotExist(SessionFileStorage::storagePath($sessionId));
    }

    public function testSessionStorageJitHelperReadCookieId(): void
    {
        $cookies = new HashTable();
        $name = new Variable(Variable::TYPE_STRING);
        $name->string('PHPSESSID');
        $value = new Variable(Variable::TYPE_STRING);
        $value->string('sess-id-1');
        $cookies->add('PHPSESSID', $value);

        $this->assertSame('sess-id-1', SessionStorageJitHelper::readCookieId('PHPSESSID', $cookies));
        $this->assertSame('', SessionStorageJitHelper::readCookieId('OTHER', $cookies));
        $this->assertSame('', SessionStorageJitHelper::readCookieId('PHPSESSID', null));
    }
}
