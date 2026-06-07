<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/** Deprecated::__construct(?string $message = null, ?string $since = null) — VM (#6369, Zend zend_attributes.c). */
final class DeprecatedConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Deprecated::__construct() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Deprecated::__construct() called without $this');
        }
        $object = $receiver->toObject();
        self::assignNullableStringProperty($object, 'message', $frame->calledArgs[1] ?? null);
        self::assignNullableStringProperty($object, 'since', $frame->calledArgs[2] ?? null);
    }

    private static function assignNullableStringProperty(
        \PHPCompiler\VM\ObjectEntry $object,
        string $name,
        ?Variable $arg,
    ): void {
        $prop = $object->getProperty($name);
        if (null === $arg) {
            $prop->null();

            return;
        }
        $value = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $value->type) {
            $prop->null();

            return;
        }
        if (Variable::TYPE_STRING !== $value->type) {
            throw new \TypeError(
                'Deprecated::__construct(): Argument #'.('message' === $name ? '1' : '2')
                .('message' === $name ? ' ($message)' : ' ($since)')
                .' must be of type ?string, '.EnumCaseSupport::typeNameForVariable($value).' given'
            );
        }
        $prop->string($value->toString());
    }
}
