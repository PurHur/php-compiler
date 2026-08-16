<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\ext\standard\VmNullStringParamDeprecation;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Shared VM wiring for ext/xmlwriter class methods (#6065).
 *
 * User-argc guards (#30818) mirror XmlReaderClassMethod / DomClassMethod:
 * Zend ArgumentCountError messages exclude $this.
 */
abstract class XmlWriterClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmXmlWriter::requireWriter(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            $label
        );
    }

    /** User args only — drop $this when present (instance call). */
    protected function userArgCount(Frame $frame, bool $hasThis = true): int
    {
        $n = \count($frame->calledArgs);

        return max(0, $hasThis ? $n - 1 : $n);
    }

    protected function requireExactUserArgCount(
        Frame $frame,
        string $function,
        int $expected,
        bool $hasThis = true
    ): void {
        $given = $this->userArgCount($frame, $hasThis);
        if ($given !== $expected) {
            throw new \ArgumentCountError(self::exactUserArgCountMessage($function, $expected, $given));
        }
    }

    protected function requireAtMostUserArgCount(
        Frame $frame,
        string $function,
        int $maximum,
        bool $hasThis = true
    ): void {
        $given = $this->userArgCount($frame, $hasThis);
        if ($given > $maximum) {
            throw new \ArgumentCountError(self::atMostUserArgCountMessage($function, $maximum, $given));
        }
    }

    protected function requireUserArgCountRange(
        Frame $frame,
        string $function,
        int $minimum,
        int $maximum,
        bool $hasThis = true
    ): void {
        $given = $this->userArgCount($frame, $hasThis);
        if ($given < $minimum) {
            throw new \ArgumentCountError(self::atLeastUserArgCountMessage($function, $minimum, $given));
        }
        if ($given > $maximum) {
            throw new \ArgumentCountError(self::atMostUserArgCountMessage($function, $maximum, $given));
        }
    }

    public static function exactUserArgCountMessage(string $function, int $expected, int $given): string
    {
        return \sprintf(
            '%s() expects exactly %d argument%s, %d given',
            $function,
            $expected,
            1 === $expected ? '' : 's',
            $given
        );
    }

    public static function atMostUserArgCountMessage(string $function, int $maximum, int $given): string
    {
        return \sprintf(
            '%s() expects at most %d argument%s, %d given',
            $function,
            $maximum,
            1 === $maximum ? '' : 's',
            $given
        );
    }

    public static function atLeastUserArgCountMessage(string $function, int $minimum, int $given): string
    {
        return \sprintf(
            '%s() expects at least %d argument%s, %d given',
            $function,
            $minimum,
            1 === $minimum ? '' : 's',
            $given
        );
    }

    /**
     * Z_PARAM_STR — soft-null DEP+coerce under php-src-strict (php-src php_xmlwriter.c; #31610).
     *
     * $label may include trailing "()" (legacy call sites); deprecation uses the bare Class::method.
     */
    protected function stringArg(
        Variable $var,
        string $label,
        int $index,
        Frame $frame,
        string $paramName = 'value'
    ): string {
        $function = str_ends_with($label, '()') ? substr($label, 0, -2) : $label;
        $display = str_ends_with($label, '()') ? $label : $label.'()';
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::rejectNullString($var, $function, $paramName, $index, $frame);
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(sprintf(
                '%s: Argument #%d ($%s) must be of type string, %s given',
                $display,
                $index + 1,
                $paramName,
                $var->toObject()->class->name
            ));
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(sprintf(
                '%s: Argument #%d ($%s) must be of type string, array given',
                $display,
                $index + 1,
                $paramName
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            // Z_PARAM_STR weak: E_DEPRECATED then coerce to '' (#31610).
            VmNullStringParamDeprecation::emit($frame, $function, $index, $paramName);

            return '';
        }

        return $var->toString();
    }

    protected function nullableStringArg(
        Variable $var,
        string $label,
        int $index,
        Frame $frame,
        string $paramName = 'value'
    ): ?string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return $this->stringArg($var, $label, $index, $frame, $paramName);
    }
}
