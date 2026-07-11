<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\session\SessionFileStorage;
use PHPCompiler\ext\standard\SessionStorageJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** SessionStorageRuntime embed + standalone route through SessionStorageJitHelper PHP (#9495, #12938). */
final class SessionStorageRuntimeShrinkTest extends TestCase
{
    public function testSessionStorageRuntimeUsesJitHelperNotStandaloneLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionStorageRuntime.php');
        $this->assertStringContainsString('SessionStorageJitHelper', $source);
        $this->assertStringNotContainsString('SessionStorageStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitLoadFromDisk', $source);
        $this->assertStringNotContainsString('emitSaveToDisk', $source);
        $this->assertStringNotContainsString('emitMergeHashtable', $source);
        $this->assertStringNotContainsString('buildStoragePathCstr', $source);
        $this->assertLessThan(520, \substr_count($source, "\n") + 1);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/SessionStorageStandaloneLlvm.php');
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
