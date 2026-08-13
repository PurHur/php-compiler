<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmNullStringParamDeprecation;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Shared VM wiring for ext/dom class methods (issue #6140). */
abstract class DomClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $classLc, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmDom::requireReceiver($frame->calledArgs[0], $classLc, $label, $frame->vmContext);
    }

    protected function stringArg(
        Variable $var,
        string $label,
        int $index,
        ?Frame $frame = null,
        string $paramName = 'value'
    ): string {
        $function = str_ends_with($label, '()') ? substr($label, 0, -2) : $label;
        if (null !== $frame && InternalStrictArg::isCallerStrict($frame)) {
            // InternalStrictArg appends "()" — strip if callers pass "Class::method()".
            InternalStrictArg::rejectNullString($var, $function, $paramName, $index, $frame);
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_NULL !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type string, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            // Z_PARAM_STR weak: E_DEPRECATED then coerce to '' (#30041, xpath.c / document.c).
            VmNullStringParamDeprecation::emit($frame, $function, $index, $paramName);

            return '';
        }

        return $var->toString();
    }

    /**
     * insertAdjacentElement/Text $where — Dom\AdjacentPosition on living Dom\Element; string on DOMElement (#20782).
     *
     * php-src: ext/dom/php_dom.stub.php — living AdjacentPosition vs legacy string
     */
    protected function adjacentPositionArg(
        ObjectEntry $receiver,
        Variable $var,
        string $method,
        ?Frame $frame = null
    ): string {
        $var = $var->resolveIndirect();
        $living = VmDomLiving::isLivingElement($receiver);
        $label = $living
            ? 'Dom\\Element::'.$method
            : 'DOMElement::'.$method;

        if ($living) {
            $fromEnum = DomAdjacentPositionEnum::tryPositionString($var);
            if (null !== $fromEnum) {
                return $fromEnum;
            }
            $given = EnumCaseSupport::isEnumCaseVariable($var)
                ? EnumCaseSupport::typeNameForVariable($var)
                : (Variable::TYPE_OBJECT === $var->type
                    ? $var->toObject()->class->name
                    : VmDom::typeLabel($var));
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($where) must be of type Dom\\AdjacentPosition, %s given',
                $label,
                $given
            ));
        }

        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($where) must be of type string, %s given',
                $label,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (null !== $frame && InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::rejectNullString($var, $label.'()', 'where', 0, $frame);
        }
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_NULL !== $var->type) {
            $given = Variable::TYPE_OBJECT === $var->type
                ? $var->toObject()->class->name
                : VmDom::typeLabel($var);
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($where) must be of type string, %s given',
                $label,
                $given
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            return '';
        }

        return $var->toString();
    }

    /**
     * DOMElement::insertAdjacentHTML $position — string only (php-src stub; living Dom\Element has no HTML API).
     */
    protected function adjacentHtmlPositionArg(
        Variable $var,
        string $label,
        int $index,
        ?Frame $frame = null,
        string $paramName = 'position'
    ): string {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            // Allow Dom\AdjacentPosition backing for copied living handlers; prefer string for legacy.
            $fromEnum = DomAdjacentPositionEnum::tryPositionString($var);
            if (null !== $fromEnum) {
                return $fromEnum;
            }
            throw new \TypeError(sprintf(
                '%s: Argument #%d ($%s) must be of type string, %s given',
                $label,
                $index + 1,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (null !== $frame && InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::rejectNullString($var, $label, $paramName, $index, $frame);
        }
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_NULL !== $var->type) {
            throw new \TypeError(sprintf(
                '%s: Argument #%d ($%s) must be of type string, %s given',
                $label,
                $index + 1,
                $paramName,
                VmDom::typeLabel($var)
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            return '';
        }

        return $var->toString();
    }

    protected function nullableStringArg(Variable $var, string $label, int $index): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?string, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    protected function domRegistryNodeReceiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s must be called on an object, %s given',
                $label,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        $classLc = strtolower($object->class->name);
        if (VmDom::CLASS_DOCUMENT === $classLc
            || VmDomLiving::CLASS_HTML_DOCUMENT === $classLc
            || VmDomLiving::CLASS_XML_DOCUMENT === $classLc
            || VmDomLiving::CLASS_DOCUMENT === $classLc
        ) {
            VmDom::ensureDocument($object);
        } elseif (VmDom::CLASS_DOCUMENT_FRAGMENT === $classLc
            || VmDomLiving::CLASS_DOCUMENT_FRAGMENT === $classLc
        ) {
            VmDom::ensureDocumentFragment($object);
        }
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(sprintf(
                '%s must be called on a DOMNode instance',
                $label
            ));
        }

        return $object;
    }

    /**
     * DOMDocument::saveXML/saveHTML — ?DOMNode $node, int $options (php-src ext/dom/document.c).
     *
     * CalledArgs may be sparse when a trailing optional is named and $node is omitted (#25182).
     *
     * @return array{0: ?ObjectEntry, 1: int}
     */
    protected function parseSaveNodeAndOptionsArgs(Frame $frame, string $label): array
    {
        $node = null;
        $options = 0;
        $hasNode = \array_key_exists(1, $frame->calledArgs);
        $hasOptions = \array_key_exists(2, $frame->calledArgs);
        // Method label without trailing "()" — VmMath int TypeError adds "():".
        $function = rtrim($label, '()');

        if ($hasNode) {
            $first = $frame->calledArgs[1]->resolveIndirect();
            // Positional saveXML(LIBXML_…) — single int is options, not a node (#6140).
            if (!$hasOptions && \in_array($first->type, [Variable::TYPE_INTEGER, Variable::TYPE_FLOAT], true)) {
                $options = $first->toInt();
            } else {
                $node = $this->saveSerializationOptionalDomNodeArg($frame->calledArgs[1], $label, 0);
                if ($hasOptions) {
                    $options = $this->zParamLongArg($frame, 2, $function, 2, 'options');
                }
            }
        } elseif ($hasOptions) {
            $options = $this->zParamLongArg($frame, 2, $function, 2, 'options');
        }

        return [$node, $options];
    }

    /**
     * Optional int $options / $flags — Z_PARAM_LONG with caller strict_types (#25768).
     *
     * $function is the method name without trailing "()" (VmMath / TypeError appends it).
     * $calledArgIndex indexes {@see Frame::$calledArgs} (includes $this for instance methods).
     * $userArgIndex is the 1-based stub argument number in the TypeError message.
     */
    protected function zParamLongArg(
        Frame $frame,
        int $calledArgIndex,
        string $function,
        int $userArgIndex,
        string $paramName
    ): int {
        // Instance methods keep $this at calledArgs[0]; InternalStrictArg::requireInt would
        // report Argument #($calledArgIndex+1). Use the stub ordinal for the message (#25768).
        if (InternalStrictArg::isCallerStrict($frame)) {
            $arg = $frame->calledArgs[$calledArgIndex]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $arg->type) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #%d ($%s) must be of type int, %s given',
                    $function,
                    $userArgIndex,
                    $paramName,
                    EnumCaseSupport::typeNameForVariable($arg)
                ));
            }

            return $arg->toInt();
        }

        return VmMath::parseZParamLongBuiltinArg(
            $frame->calledArgs[$calledArgIndex],
            $function,
            $userArgIndex,
            $paramName,
            $frame
        );
    }

    protected function saveSerializationOptionalDomNodeArg(Variable $var, string $label, int $index): ?ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(\sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                $object->class->name
            ));
        }

        return $object;
    }

    /**
     * Z_PARAM_OBJECT_OF_CLASS(DOMNode) — Zend TypeError shape (php_dom.stub.php; #30410).
     *
     * $function is Class::method without trailing "()". Mutations are declared on DOMNode
     * even when invoked on DOMElement/Document/Fragment.
     */
    protected function requireDomNodeArg(
        Variable $var,
        string $function,
        int $userArgIndex,
        string $paramName
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type DOMNode, %s given',
                $function,
                $userArgIndex,
                $paramName,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        // php-src stub: DOMNode $node — accept Document/etc.; hierarchy rejects later (#22698).
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type DOMNode, %s given',
                $function,
                $userArgIndex,
                $paramName,
                $object->class->name
            ));
        }

        return $object;
    }

    /**
     * User argc for instance methods — Zend excludes $this (#30616).
     */
    protected function userArgCount(Frame $frame): int
    {
        return max(0, \count($frame->calledArgs) - 1);
    }

    /**
     * Exact user arity → Zend ArgumentCountError (#30616; php-src stubs).
     *
     * $function is Class::method without trailing "()".
     */
    protected function requireExactUserArgCount(Frame $frame, string $function, int $expected): void
    {
        $given = $this->userArgCount($frame);
        if ($given !== $expected) {
            throw new \ArgumentCountError(self::exactUserArgCountMessage($function, $expected, $given));
        }
    }

    /**
     * At-most user arity → Zend ArgumentCountError (#30616).
     */
    protected function requireAtMostUserArgCount(Frame $frame, string $function, int $maximum): void
    {
        $given = $this->userArgCount($frame);
        if ($given > $maximum) {
            throw new \ArgumentCountError(self::atMostUserArgCountMessage($function, $maximum, $given));
        }
    }

    /**
     * Inclusive user-arity range → Zend ArgumentCountError (#30616).
     */
    protected function requireUserArgCountRange(Frame $frame, string $function, int $minimum, int $maximum): void
    {
        $given = $this->userArgCount($frame);
        if ($given < $minimum) {
            throw new \ArgumentCountError(self::atLeastUserArgCountMessage($function, $minimum, $given));
        }
        if ($given > $maximum) {
            throw new \ArgumentCountError(self::atMostUserArgCountMessage($function, $maximum, $given));
        }
    }

    /** @internal Shared with VmDomJitDispatch ($extra is user args only). */
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

    /** @internal Shared with VmDomJitDispatch. */
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

    /** @internal Shared with VmDomJitDispatch. */
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

}
