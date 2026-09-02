<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\JIT;
use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * SPL extension module entry (php-src ext/spl/php_spl.c; issue #4769).
 */
class Module extends ModuleAbstract
{
    /**
     * SplHeap family thin-AOT Call proxies — owned by ext/spl (#36204 / #26784).
     *
     * KIND_* constants live on SplHeapBuiltin; Context must not import ext\spl.
     */
    public function jitInit(JIT\Context $context): void
    {
        foreach ([
            'splmaxheap' => SplHeapBuiltin::KIND_MAX,
            'splminheap' => SplHeapBuiltin::KIND_MIN,
            'splheap' => SplHeapBuiltin::KIND_USER,
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
    }

    public function getFunctions(): array
    {
        return [
            new spl_classes(),
        ];
    }
}
