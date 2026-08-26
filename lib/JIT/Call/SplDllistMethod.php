<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\SplDllistJitHelper;
use PHPLLVM\Value;

/**
 * SplDoublyLinkedList / SplQueue / SplStack thin-AOT methods (#26790, ext/spl/spl_dllist.c).
 */
final class SplDllistMethod implements Call
{
    public function __construct(
        private readonly string $method,
        private readonly string $className,
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException($this->className.'::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            '__construct' => SplDllistJitHelper::compileConstruct($context, $args[0], $this->className),
            // push lives on SplDoublyLinkedList; enqueue is SplQueue-only (php-src ACE cites defining class)
            'push', 'enqueue' => $this->callExactArg(
                $context,
                $args,
                'enqueue' === strtolower($this->method)
                    ? 'SplQueue::enqueue'
                    : 'SplDoublyLinkedList::push',
                1,
                static fn (Context $ctx, Variable $self, Variable $value): Value => SplDllistJitHelper::compilePush(
                    $ctx,
                    $self,
                    $value
                )
            ),
            'unshift' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::unshift',
                1,
                static fn (Context $ctx, Variable $self, Variable $value): Value => SplDllistJitHelper::compileUnshift(
                    $ctx,
                    $self,
                    $value
                )
            ),
            'pop' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::pop',
                0,
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compilePop($ctx, $self)
            ),
            'shift', 'dequeue' => $this->callExactArg(
                $context,
                $args,
                'dequeue' === strtolower($this->method) ? 'SplQueue::dequeue' : 'SplDoublyLinkedList::shift',
                0,
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileShift($ctx, $self)
            ),
            'top' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::top',
                0,
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileTop($ctx, $self)
            ),
            'bottom' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::bottom',
                0,
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileBottom($ctx, $self)
            ),
            // php-src zim_SplDoublyLinkedList_count — Countable (#32910)
            'count' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::count',
                0,
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileCount($ctx, $self)
            ),
            // php-src zim_SplDoublyLinkedList_isEmpty (#33973) — was silent null without proxy
            'isempty' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::isEmpty',
                0,
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileIsEmpty($ctx, $self)
            ),
            // php-src ArrayAccess + iterator mode (#33987) — thin AOT silent-nulled without proxy (#579)
            'offsetget' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::offsetGet',
                1,
                static fn (Context $ctx, Variable $self, Variable $index): Value => SplDllistJitHelper::compileOffsetGet(
                    $ctx,
                    $self,
                    $index
                )
            ),
            'offsetexists' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::offsetExists',
                1,
                static fn (Context $ctx, Variable $self, Variable $index): Value => SplDllistJitHelper::compileOffsetExists(
                    $ctx,
                    $self,
                    $index
                )
            ),
            'offsetset' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::offsetSet',
                2,
                static fn (Context $ctx, Variable $self, Variable $index, Variable $value): Value => SplDllistJitHelper::compileOffsetSet(
                    $ctx,
                    $self,
                    $index,
                    $value
                )
            ),
            'offsetunset' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::offsetUnset',
                1,
                static fn (Context $ctx, Variable $self, Variable $index): Value => SplDllistJitHelper::compileOffsetUnset(
                    $ctx,
                    $self,
                    $index
                )
            ),
            'setiteratormode' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::setIteratorMode',
                1,
                fn (Context $ctx, Variable $self, Variable $mode): Value => SplDllistJitHelper::compileSetIteratorMode(
                    $ctx,
                    $self,
                    $mode,
                    $this->className
                )
            ),
            'getiteratormode' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::getIteratorMode',
                0,
                fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileGetIteratorMode(
                    $ctx,
                    $self,
                    $this->className
                )
            ),
            // php-src Iterator protocol — without proxy thin AOT silent-nulls (#579 / #34976)
            'rewind' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::rewind',
                0,
                fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileRewind(
                    $ctx,
                    $self,
                    $this->className
                )
            ),
            'valid' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::valid',
                0,
                fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileValid(
                    $ctx,
                    $self,
                    $this->className
                )
            ),
            'current' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::current',
                0,
                fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileCurrent(
                    $ctx,
                    $self,
                    $this->className
                )
            ),
            'key' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::key',
                0,
                fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileKey(
                    $ctx,
                    $self,
                    $this->className
                )
            ),
            'next' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::next',
                0,
                fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileNext(
                    $ctx,
                    $self,
                    $this->className
                )
            ),
            // php-src zim_SplDoublyLinkedList_serialize/unserialize — silent-null (#579 / #35111)
            'serialize' => $this->callExactArg(
                $context,
                $args,
                $this->className.'::serialize',
                0,
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileLegacySerialize(
                    $ctx,
                    $self
                )
            ),
            'unserialize' => $this->callExactArg(
                $context,
                $args,
                $this->className.'::unserialize',
                1,
                fn (Context $ctx, Variable $self, Variable $data): Value => SplDllistJitHelper::compileLegacyUnserialize(
                    $ctx,
                    $self,
                    $data,
                    $this->className
                )
            ),
            default => throw new \LogicException(
                $this->className.' JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /**
     * php-src ZEND_PARSE_PARAMETERS_* — ACE cites defining class (#30911, #30964).
     *
     * @param callable(Context, Variable...): Value $emit
     */
    private function callExactArg(
        Context $context,
        array $args,
        string $function,
        int $expected,
        callable $emit
    ): Value {
        $userArgCount = max(0, \count($args) - 1);
        if ($userArgCount !== $expected) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($function, $expected, $userArgCount)
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'spl_dllist_'.strtolower($this->method).'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        return $emit($context, ...$args);
    }
}
