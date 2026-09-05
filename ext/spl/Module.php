<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\JIT;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * SPL extension module entry (php-src ext/spl/php_spl.c; issue #4769).
 */
class Module extends ModuleAbstract
{
    /**
     * SplHeap family thin-AOT Call proxies — owned by ext/spl (#36204 / #26784).
     *
     * KIND_* constants live on SplHeapJitHelper; Context / JIT helpers must not import ext\spl.
     */
    public function jitInit(JIT\Context $context): void
    {
        foreach ([
            'splmaxheap' => \PHPCompiler\VM\SplHeapJitHelper::KIND_MAX,
            'splminheap' => \PHPCompiler\VM\SplHeapJitHelper::KIND_MIN,
            'splheap' => \PHPCompiler\VM\SplHeapJitHelper::KIND_USER,
        ] as $heapLc => $heapKind) {
            foreach ([
                '__construct', 'insert', 'extract', 'top', 'count', 'isempty',
                'rewind', 'valid', 'current', 'key', 'next',
            ] as $heapMethod) {
                $context->functionProxies[$heapLc.'::'.$heapMethod] = new JIT\Call\SplHeapMethod(
                    $heapMethod,
                    $heapKind
                );
            }
        }
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        // lib/VM must not import ext\spl — register the ArrayObject/ArrayIterator bridge (#36204).
        VM\SplArraySupport::setHandler(new VmSplArrayHandler());
        VM\InternalIteratorSupport::setFromTable(
            static fn (VM\Context $ctx, VM\HashTable $table): VM\ObjectEntry => InternalIteratorBuiltin::fromTable($ctx, $table)
        );
        VM\InternalIteratorSupport::setFromLiveHandler(
            static fn (VM\Context $ctx, VM\InternalIteratorLiveHandler $handler): VM\ObjectEntry => InternalIteratorBuiltin::fromLiveHandler($ctx, $handler)
        );
        VM\SplDualIteratorSupport::setHasStateFor(
            static fn (VM\ObjectEntry $object): bool => SplDualIteratorStorage::hasStateFor($object)
        );
        VM\SplDualIteratorSupport::setTransferState(
            static function (int $fromId, int $toId): void {
                SplDualIteratorStorage::transferState($fromId, $toId);
            }
        );
    }

    public function getFunctions(): array
    {
        return [
            new spl_classes(),
        ];
    }
}
