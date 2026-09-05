<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\VM\FilterVmRuntimeSupport;
use PHPCompiler\VM\InternalIteratorSupport;
use PHPCompiler\VM\SplDualIteratorSupport;
use PHPCompiler\VM\SplHeapJitHelper;
use PHPCompiler\VM\SplPriorityQueueJitHelper;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * lib/ must not import ext\\spl / ext\\filter / ext\\posix for these surfaces (#36204).
 */
final class SplPosixFilterVmHooks36204Test extends TestCase
{
    /** @return list<string> */
    private static function libPaths(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            $root.'/lib/VM/SplHeapJitHelper.php',
            $root.'/lib/VM/SplPriorityQueueJitHelper.php',
            $root.'/lib/VM/EnumCaseSupport.php',
            $root.'/lib/VM/ClassConstMaterializer.php',
            $root.'/lib/VM/Builtin/DatePeriodGetIterator.php',
            $root.'/lib/VM/Builtin/WeakMapGetIterator.php',
            $root.'/lib/VM/WeakMapInternalIteratorHandler.php',
            $root.'/lib/VM/Context.php',
            $root.'/lib/JIT/Builtin/PosixSessionRuntime.php',
        ];
    }

    public function testLibSurfacesHaveNoDirectExtImports(): void
    {
        foreach (self::libPaths() as $path) {
            $src = (string) file_get_contents($path);
            self::assertStringNotContainsString(
                'PHPCompiler\\ext\\spl',
                $src,
                basename($path).' must not import ext\\spl'
            );
            self::assertStringNotContainsString(
                'PHPCompiler\\ext\\filter',
                $src,
                basename($path).' must not import ext\\filter'
            );
            self::assertStringNotContainsString(
                'PHPCompiler\\ext\\posix\\JitPosix',
                $src,
                basename($path).' must not import JitPosix'
            );
        }
    }

    public function testHeapAndQueueConstantsMatchPhpSrc(): void
    {
        self::assertSame(1, SplHeapJitHelper::KIND_MAX);
        self::assertSame(-1, SplHeapJitHelper::KIND_MIN);
        self::assertSame(0, SplHeapJitHelper::KIND_USER);
        self::assertSame(1, SplPriorityQueueJitHelper::EXTR_DATA);
        self::assertSame(2, SplPriorityQueueJitHelper::EXTR_PRIORITY);
        self::assertSame(3, SplPriorityQueueJitHelper::EXTR_BOTH);
    }

    public function testSupportHooksUnsetAreSafe(): void
    {
        InternalIteratorSupport::clear();
        SplDualIteratorSupport::clear();
        FilterVmRuntimeSupport::clear();
        self::assertFalse(SplDualIteratorSupport::hasStateFor(
            new \PHPCompiler\VM\ObjectEntry(new \PHPCompiler\VM\ClassEntry('stdClass'))
        ));
        SplDualIteratorSupport::transferState(1, 2);
        self::assertNull(FilterVmRuntimeSupport::variableForName('FILTER_VALIDATE_EMAIL'));
    }

    public function testFilterHookDelegatesWhenRegistered(): void
    {
        FilterVmRuntimeSupport::clear();
        FilterVmRuntimeSupport::setVariableForName(
            static function (string $name): ?Variable {
                if ('FILTER_VALIDATE_EMAIL' !== $name) {
                    return null;
                }
                $v = new Variable(Variable::TYPE_INTEGER);
                $v->int(274);

                return $v;
            }
        );
        $got = FilterVmRuntimeSupport::variableForName('FILTER_VALIDATE_EMAIL');
        self::assertNotNull($got);
        self::assertSame(274, $got->toInt());
        FilterVmRuntimeSupport::clear();
    }

    public function testSupportClassesLoad(): void
    {
        self::assertTrue(class_exists(InternalIteratorSupport::class));
        self::assertTrue(class_exists(SplDualIteratorSupport::class));
        self::assertTrue(class_exists(FilterVmRuntimeSupport::class));
        self::assertTrue(interface_exists(\PHPCompiler\VM\InternalIteratorLiveHandler::class));
    }
}
