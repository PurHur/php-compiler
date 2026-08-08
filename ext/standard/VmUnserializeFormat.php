<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\DatePeriodSupport;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Native unserialize() scalar/array decoder — pairs with {@see VmSerializeFormat} (issue #8191).
 *
 * php-src: ext/standard/var_unserializer.c
 */
final class VmUnserializeFormat
{
    private const DEFAULT_MAX_DEPTH = 4096;

    /**
     * @param array<string, mixed>|null $options
     */
    private static function resolveMaxDepth(?array $options): int
    {
        if (null !== $options && \array_key_exists('max_depth', $options)) {
            return (int) $options['max_depth'];
        }

        return VmIni::getUnserializeMaxDepth();
    }

    private static ?int $lastErrorOffset = null;

    private static ?int $lastPayloadLength = null;

    /** Set when parse fails because max_depth was exceeded (ext/standard/var.c, #13715). */
    private static ?int $lastMaxDepthExceeded = null;

    private int $pos = 0;

    private readonly int $length;

    /** @var list<VmUnserializeCell|null> php-src var_hash — 1-indexed */
    private array $refTable = [null];

    private function __construct(
        private readonly string $payload,
        private readonly int $maxDepth,
    ) {
        $this->length = \strlen($payload);
    }

    /**
     * @param array<string, mixed>|null $options
     *
     * @return array<mixed>|bool|float|int|null|string|false
     */
    public static function decodePayload(string $payload, ?array $options = null): mixed
    {
        $root = self::parseRootCell($payload, $options);
        if (false === $root) {
            return false;
        }

        return self::materializeCell($root);
    }

    /**
     * @param array<string, mixed>|null $options
     */
    public static function decodeToVariable(string $payload, ?array $options = null): Variable|false
    {
        $root = self::parseRootCell($payload, $options);
        if (false === $root) {
            return false;
        }

        $canonical = [];
        $slotForCell = [];

        return self::unwrapUnserializeRoot(self::cellToVariable($root, $canonical, $slotForCell));
    }

    public static function decodeToVariableWithContext(
        Context $ctx,
        string $payload,
        ?array $options = null,
        ?Frame $frame = null
    ): Variable|false {
        $root = self::parseRootCell($payload, $options);
        if (false === $root) {
            return false;
        }

        $canonical = [];
        $slotForCell = [];

        return self::unwrapUnserializeRoot(
            self::cellToVariableWithContext($ctx, $root, $canonical, $slotForCell, $frame, $options)
        );
    }

    /** Root array/object values are stored as ISREF wrappers; callers want the concrete zval (#22652). */
    private static function unwrapUnserializeRoot(Variable $var): Variable
    {
        if ($var->isIndirect()) {
            return $var->resolveIndirect();
        }

        return $var;
    }

    /**
     * Decode O: property bag with r:1 self-reference (ext/standard/var_unserializer.re, #12082).
     *
     * @param array<string, mixed>|null $options unserialize() options (allowed_classes; #29065)
     */
    public static function decodeObjectPropertyBag(
        Context $ctx,
        ClassEntry $class,
        int $propCount,
        string $inner,
        ?Frame $frame = null,
        ?array $options = null
    ): Variable|false {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $objectVar = new Variable();
        $objectVar->object($entry);

        $parser = new self('a:'.$propCount.':{'.$inner.'}', self::DEFAULT_MAX_DEPTH);
        $rootCell = new VmUnserializeCell();
        $rootCell->value = new VmUnserializeRootObject($objectVar);
        $parser->refTable[1] = $rootCell;

        $propsCell = $parser->parseArray(0);
        if (false === $propsCell || $parser->pos !== $parser->length) {
            return false;
        }
        if (!\is_array($propsCell->value)) {
            return false;
        }
        foreach ($propsCell->value as $name => $child) {
            \assert($child instanceof VmUnserializeCell);
            $localCanonical = [];
            $localSlotForCell = [];
            $value = self::cellToVariableWithContext(
                $ctx,
                $child,
                $localCanonical,
                $localSlotForCell,
                $frame,
                $options
            );
            $ctx->runtime->vm()->assignUnserializeProperty($entry, (string) $name, $value, $frame);
        }

        return $objectVar;
    }

    /**
     * @param array<string, mixed>|null $options
     */
    private static function parseRootCell(string $payload, ?array $options = null): VmUnserializeCell|false
    {
        self::$lastErrorOffset = null;
        self::$lastPayloadLength = null;
        self::$lastMaxDepthExceeded = null;
        if ('' === $payload) {
            return false;
        }
        $maxDepth = self::resolveMaxDepth($options);

        $parser = new self($payload, $maxDepth);
        $value = $parser->parseValue(0);
        if (false === $value) {
            return false;
        }
        if ($parser->pos !== $parser->length) {
            return $parser->failCell();
        }

        return $value;
    }

    public static function lastErrorOffset(): ?int
    {
        return self::$lastErrorOffset;
    }

    public static function lastPayloadLength(): ?int
    {
        return self::$lastPayloadLength;
    }

    public static function lastMaxDepthExceeded(): ?int
    {
        return self::$lastMaxDepthExceeded;
    }

    /** @return false */
    private function fail(?int $errorOffset = null): bool
    {
        self::$lastErrorOffset = $errorOffset ?? $this->pos;
        self::$lastPayloadLength = $this->length;

        return false;
    }

    private function failCell(?int $errorOffset = null): false
    {
        $this->fail($errorOffset);

        return false;
    }

    /** @return false */
    private function failDepthExceeded(): false
    {
        self::$lastMaxDepthExceeded = $this->maxDepth;

        return $this->failCell();
    }

    private function pushRef(VmUnserializeCell $cell): void
    {
        $this->refTable[] = $cell;
    }

    private function parseValue(int $depth): VmUnserializeCell|false
    {
        if ($this->pos >= $this->length) {
            return $this->failCell();
        }

        $type = $this->payload[$this->pos];
        return match ($type) {
            'N' => $this->parseNull(),
            'b' => $this->parseBool(),
            'i' => $this->parseInt(),
            'd' => $this->parseDouble(),
            's' => $this->parseString(),
            'a' => $this->parseArray($depth),
            'O' => $this->parseObject($depth),
            'E' => $this->parseEnum(),
            'r' => $this->parseObjectReference(),
            'R' => $this->parseReference(),
            default => $this->failCell(),
        };
    }

    /** php-src object reference marker — r: index; (ext/standard/var_unserializer.re, #12082) */
    private function parseObjectReference(): VmUnserializeCell|false
    {
        if (!$this->expect('r:')) {
            return $this->failCell();
        }
        $index = $this->readUnsignedInteger();
        if (null === $index || !$this->expect(';')) {
            return $this->failCell();
        }
        if (!isset($this->refTable[$index])) {
            return $this->failCell();
        }

        // php-src var_push happens before the r: match (cursor[0] != 'R'); reuse cell.
        return $this->refTable[$index];
    }

    /**
     * @return VmUnserializeCell|false
     */
    private function parseObject(int $depth): VmUnserializeCell|false
    {
        if (!$this->expect('O:')) {
            return $this->failCell();
        }
        $classLen = $this->readUnsignedInteger();
        if (null === $classLen || !$this->expect(':"')) {
            return $this->failCell();
        }
        $className = $this->readStringContent($classLen);
        if (null === $className || !$this->expect('":')) {
            return $this->failCell();
        }
        $propCount = $this->readUnsignedInteger();
        if (null === $propCount || !$this->expect(':')) {
            return $this->failCell();
        }
        if (!$this->expect('{')) {
            return $this->failCell();
        }
        $start = $this->pos;
        /** @var array<int|string, VmUnserializeCell> $properties */
        $properties = [];
        for ($i = 0; $i < $propCount; ++$i) {
            if ($depth + 1 > $this->maxDepth) {
                return $this->failDepthExceeded();
            }
            $keyCell = $this->parseArrayKey();
            if (false === $keyCell) {
                return $this->failCell();
            }
            $before = $this->pos;
            $valueCell = $this->parseValue($depth + 1);
            if (false === $valueCell || $this->pos <= $before) {
                return $this->failCell();
            }
            $properties[self::cellScalar($keyCell)] = $valueCell;
        }
        if (!$this->expect('}')) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = new VmUnserializeObjectPayload($className, $properties, $start, $this->pos);
        $this->pushRef($cell);

        return $cell;
    }

    /** php-src E:len:"ClassName:CaseName"; — enum wire format (var_unserializer.re). */
    private function parseEnum(): VmUnserializeCell|false
    {
        if (!$this->expect('E:')) {
            return $this->failCell();
        }
        $len = $this->readUnsignedInteger();
        if (null === $len || !$this->expect(':"')) {
            return $this->failCell();
        }
        $payload = $this->readStringContent($len);
        if (null === $payload || !$this->expect('";')) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = new VmUnserializeEnumPayload($payload);
        $this->pushRef($cell);

        return $cell;
    }

    /**
     * php-src var_push_deref — R: index; (#12080, #22652).
     *
     * Uppercase R: skips var_push at php_var_unserialize_internal entry (`*p != 'R'`).
     */
    private function parseReference(): VmUnserializeCell|false
    {
        if (!$this->expect('R:')) {
            return $this->failCell();
        }
        $index = $this->readUnsignedInteger();
        if (null === $index || !$this->expect(';')) {
            return $this->failCell();
        }
        if (!isset($this->refTable[$index])) {
            return $this->failCell();
        }

        return $this->refTable[$index];
    }

    private function parseNull(): VmUnserializeCell|false
    {
        if (!$this->expect('N;')) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = null;
        $this->pushRef($cell);

        return $cell;
    }

    private function parseBool(): VmUnserializeCell|false
    {
        if (!$this->expect('b:')) {
            return $this->failCell();
        }
        $digit = $this->readDigit();
        if (null === $digit || !$this->expect(';')) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = 1 === $digit;
        $this->pushRef($cell);

        return $cell;
    }

    private function parseInt(bool $trackRef = true): VmUnserializeCell|false
    {
        if (!$this->expect('i:')) {
            return $this->failCell();
        }
        $number = $this->readSignedInteger();
        if (null === $number || !$this->expect(';')) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = $number;
        if ($trackRef) {
            $this->pushRef($cell);
        }

        return $cell;
    }

    private function parseDouble(): VmUnserializeCell|false
    {
        if (!$this->expect('d:')) {
            return $this->failCell();
        }
        $start = $this->pos;
        while ($this->pos < $this->length && ';' !== $this->payload[$this->pos]) {
            ++$this->pos;
        }
        if ($this->pos >= $this->length) {
            return $this->failCell();
        }
        $literal = \substr($this->payload, $start, $this->pos - $start);
        ++$this->pos;

        $decoded = match ($literal) {
            'NAN' => \NAN,
            'INF' => \INF,
            '-INF' => -\INF,
            default => is_numeric($literal) ? (float) $literal : false,
        };
        if (false === $decoded) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = $decoded;
        $this->pushRef($cell);

        return $cell;
    }

    private function parseString(bool $trackRef = true): VmUnserializeCell|false
    {
        if (!$this->expect('s:')) {
            return $this->failCell();
        }
        $len = $this->readUnsignedInteger();
        if (null === $len || !$this->expect(':"')) {
            return $this->failCell();
        }
        $content = $this->readStringContent($len);
        if (null === $content || !$this->expect('";')) {
            return $this->failCell();
        }
        $cell = new VmUnserializeCell();
        $cell->value = $content;
        if ($trackRef) {
            $this->pushRef($cell);
        }

        return $cell;
    }

    /**
     * @return VmUnserializeCell|false
     */
    private function parseArray(int $depth): VmUnserializeCell|false
    {
        $start = $this->pos;
        if (!$this->expect('a:')) {
            return $this->failCell($start);
        }
        $count = $this->readUnsignedInteger();
        if (null === $count || !$this->expect(':')) {
            return $this->failCell($start);
        }
        if (!$this->expect('{')) {
            return $this->failCell($start);
        }

        // php-src var_push(rval) before parsing nested data — enables R: self-refs (#22652).
        $cell = new VmUnserializeCell();
        $cell->value = [];
        $this->pushRef($cell);

        /** @var array<int|string, VmUnserializeCell> $elements */
        $elements = [];
        for ($i = 0; $i < $count; ++$i) {
            if ($depth + 1 > $this->maxDepth) {
                return $this->failDepthExceeded();
            }
            $keyCell = $this->parseArrayKey();
            if (false === $keyCell) {
                return $this->failCell();
            }
            $before = $this->pos;
            $valueCell = $this->parseValue($depth + 1);
            if (false === $valueCell || $this->pos <= $before) {
                return $this->failCell();
            }
            $elements[self::cellScalar($keyCell)] = $valueCell;
        }
        if (!$this->expect('}')) {
            return $this->failCell();
        }

        $cell->value = $elements;

        return $cell;
    }

    /**
     * Array keys are unserialized with var_hash=NULL in php-src (process_nested_array_data) —
     * they must not consume R:/r: indices (#22652).
     */
    private function parseArrayKey(): VmUnserializeCell|false
    {
        if ($this->pos >= $this->length) {
            return $this->failCell();
        }
        if ('i' === $this->payload[$this->pos]) {
            return $this->parseInt(false);
        }
        if ('s' === $this->payload[$this->pos]) {
            return $this->parseString(false);
        }

        return $this->failCell();
    }

    private static function cellScalar(VmUnserializeCell $cell): int|string
    {
        if (!\is_int($cell->value) && !\is_string($cell->value)) {
            throw new \LogicException('unserialize() array key must be int or string');
        }

        return $cell->value;
    }

    /**
     * @param array<int, Variable> $canonical
     * @param array<int, Variable> $slotForCell
     */
    private static function cellToVariable(VmUnserializeCell $cell, array &$canonical, array &$slotForCell): Variable
    {
        $id = spl_object_id($cell);
        if (isset($slotForCell[$id])) {
            return $slotForCell[$id];
        }
        if (isset($canonical[$id])) {
            $alias = new Variable();
            $alias->indirect($canonical[$id]);
            $slotForCell[$id] = $alias;

            return $alias;
        }

        if (\is_array($cell->value)) {
            $var = new Variable();
            // Register before children so R: self-refs resolve (#22652, var_unserializer.re).
            $canonical[$id] = $var;
            $ht = new HashTable();
            $isList = self::isListCellMap($cell->value);
            foreach ($cell->value as $key => $child) {
                \assert($child instanceof VmUnserializeCell);
                $slot = self::cellToVariable($child, $canonical, $slotForCell);
                if ($isList) {
                    if ($slot->isIndirect()) {
                        $ht->updateIndirectIndex((int) $key, $slot);
                    } else {
                        $ht->addIndex((int) $key, $slot);
                    }
                } else {
                    if ($slot->isIndirect()) {
                        $ht->updateIndirect((string) $key, $slot);
                    } else {
                        $ht->add((string) $key, $slot);
                    }
                }
            }
            $var->array($ht);
            // Match scalar path: element slots are ISREF wrappers so R: aliases share storage.
            $wrapper = new Variable();
            $wrapper->indirect($var);
            $slotForCell[$id] = $wrapper;

            return $wrapper;
        }

        if ($cell->value instanceof VmUnserializeRootObject) {
            $canonical[$id] = $cell->value->objectVar;
            $slotForCell[$id] = $cell->value->objectVar;

            return $cell->value->objectVar;
        }

        if ($cell->value instanceof VmUnserializeEnumPayload) {
            throw new \LogicException(
                'unserialize() nested enum requires Context in this compiler build'
            );
        }

        if ($cell->value instanceof VmUnserializeObjectPayload) {
            throw new \LogicException(
                'unserialize() nested object requires Context in this compiler build'
            );
        }

        $storage = new Variable();
        if (null === $cell->value) {
            $storage->null();
        } elseif (\is_bool($cell->value)) {
            $storage->bool($cell->value);
        } elseif (\is_int($cell->value)) {
            $storage->int($cell->value);
        } elseif (\is_float($cell->value)) {
            $storage->float($cell->value);
        } elseif (\is_string($cell->value)) {
            $storage->string($cell->value);
        } else {
            throw new \LogicException('unserialize() result type not supported in this compiler build');
        }
        $canonical[$id] = $storage;
        $wrapper = new Variable();
        $wrapper->indirect($storage);
        $slotForCell[$id] = $wrapper;

        return $wrapper;
    }

    /**
     * @param array<int, Variable>      $canonical
     * @param array<int, Variable>      $slotForCell
     * @param array<string, mixed>|null $options
     */
    private static function cellToVariableWithContext(
        Context $ctx,
        VmUnserializeCell $cell,
        array &$canonical,
        array &$slotForCell,
        ?Frame $frame,
        ?array $options = null
    ): Variable {
        $id = spl_object_id($cell);
        if (isset($slotForCell[$id])) {
            return $slotForCell[$id];
        }
        if (isset($canonical[$id])) {
            $alias = new Variable();
            $alias->indirect($canonical[$id]);
            $slotForCell[$id] = $alias;

            return $alias;
        }

        if ($cell->value instanceof VmUnserializeEnumPayload) {
            $colonPos = strrpos($cell->value->payload, ':');
            if (false === $colonPos || 0 === $colonPos) {
                throw new \LogicException('unserialize(): invalid enum case payload');
            }
            $className = \substr($cell->value->payload, 0, $colonPos);
            $caseName = \substr($cell->value->payload, $colonPos + 1);
            $resolved = VmSerialize::resolveEnumCaseVariable($ctx, $className, $caseName);
            if (null === $resolved) {
                throw new \LogicException('unserialize(): invalid enum case in this compiler build');
            }
            $canonical[$id] = $resolved;
            $slotForCell[$id] = $resolved;

            return $resolved;
        }

        if ($cell->value instanceof VmUnserializeObjectPayload) {
            $payload = $cell->value;
            // php-src var_unserializer.re — allowed_classes before class load (#29065 / #4638).
            if (!VmSerialize::isClassAllowedForUnserialize($payload->className, $options)) {
                return self::materializeIncompleteObjectFromPayload(
                    $ctx,
                    $payload,
                    $canonical,
                    $slotForCell,
                    $frame,
                    $options,
                    $id
                );
            }
            $lc = strtolower($payload->className);
            if (!isset($ctx->classes[$lc])) {
                $ctx->autoloadClass($payload->className);
            }
            $class = $ctx->classes[$lc] ?? null;
            if (null === $class) {
                throw new \LogicException(
                    'unserialize(): class '.$payload->className.' not found in this compiler build'
                );
            }
            if ($class->isInterface || $class->isTrait || $class->isEnum || $class->isAbstract) {
                throw new \LogicException('unserialize(): invalid object class in this compiler build');
            }
            // ext/date wire objects: nested O: must use restore helpers, not raw property copy (#22447).
            // Top-level DateTime/DateInterval already special-cased in VmSerialize::unserializePayload.
            $dateRestored = self::tryRestoreDateExtensionObject(
                $ctx,
                $lc,
                $payload,
                $canonical,
                $slotForCell,
                $frame,
                $id,
                $options
            );
            if (null !== $dateRestored) {
                return $dateRestored;
            }
            if (isset($class->methods['__unserialize'])) {
                $ht = new HashTable();
                foreach ($payload->properties as $name => $child) {
                    \assert($child instanceof VmUnserializeCell);
                    $slot = self::cellToVariableWithContext(
                        $ctx,
                        $child,
                        $canonical,
                        $slotForCell,
                        $frame,
                        $options
                    );
                    if (\is_int($name)) {
                        $ht->addIndex($name, $slot);
                    } else {
                        $ht->add((string) $name, $slot);
                    }
                }
                $dataVar = new Variable();
                $dataVar->array($ht);

                return VmSerialize::instantiateWithUnserializeData($ctx, $class, $dataVar);
            }
            $entry = new ObjectEntry($class);
            $entry->constructed = true;
            $objectVar = new Variable();
            $objectVar->object($entry);
            $canonical[$id] = $objectVar;
            $slotForCell[$id] = $objectVar;
            foreach ($payload->properties as $name => $child) {
                \assert($child instanceof VmUnserializeCell);
                $value = self::cellToVariableWithContext(
                    $ctx,
                    $child,
                    $canonical,
                    $slotForCell,
                    $frame,
                    $options
                );
                $ctx->runtime->vm()->assignUnserializeProperty($entry, (string) $name, $value, $frame);
            }

            return $objectVar;
        }

        if ($cell->value instanceof VmUnserializeEnumPayload) {
            $resolved = VmSerialize::resolveEnumCaseFromWirePayload($ctx, $cell->value->payload);
            if (null === $resolved) {
                $var = new Variable();
                $var->bool(false);

                return $var;
            }
            $slotForCell[$id] = $resolved;

            return $resolved;
        }

        if (\is_array($cell->value)) {
            $var = new Variable();
            // Register before children so R: self-refs resolve (#22652, var_unserializer.re).
            $canonical[$id] = $var;
            $ht = new HashTable();
            $isList = self::isListCellMap($cell->value);
            foreach ($cell->value as $key => $child) {
                \assert($child instanceof VmUnserializeCell);
                $slot = self::cellToVariableWithContext(
                    $ctx,
                    $child,
                    $canonical,
                    $slotForCell,
                    $frame,
                    $options
                );
                if ($isList) {
                    if ($slot->isIndirect()) {
                        $ht->updateIndirectIndex((int) $key, $slot);
                    } else {
                        $ht->addIndex((int) $key, $slot);
                    }
                } else {
                    if ($slot->isIndirect()) {
                        $ht->updateIndirect((string) $key, $slot);
                    } else {
                        $ht->add((string) $key, $slot);
                    }
                }
            }
            $var->array($ht);
            $wrapper = new Variable();
            $wrapper->indirect($var);
            $slotForCell[$id] = $wrapper;

            return $wrapper;
        }

        return self::cellToVariable($cell, $canonical, $slotForCell);
    }

    /**
     * Zend __PHP_Incomplete_Class for disallowed (or filtered) O: cells (#29065).
     *
     * @param array<int, Variable>      $canonical
     * @param array<int, Variable>      $slotForCell
     * @param array<string, mixed>|null $options
     */
    private static function materializeIncompleteObjectFromPayload(
        Context $ctx,
        VmUnserializeObjectPayload $payload,
        array &$canonical,
        array &$slotForCell,
        ?Frame $frame,
        ?array $options,
        int $id
    ): Variable {
        $icClass = $ctx->classes['__php_incomplete_class'] ?? null;
        if (null === $icClass) {
            throw new \LogicException('__PHP_Incomplete_Class is not registered in this compiler build');
        }
        $entry = new ObjectEntry($icClass);
        $entry->constructed = true;
        $nameProp = $entry->allocateProperty('__PHP_Incomplete_Class_Name');
        $nameProp->string($payload->className);
        $objectVar = new Variable();
        $objectVar->object($entry);
        $canonical[$id] = $objectVar;
        $slotForCell[$id] = $objectVar;
        foreach ($payload->properties as $name => $child) {
            \assert($child instanceof VmUnserializeCell);
            $value = self::cellToVariableWithContext(
                $ctx,
                $child,
                $canonical,
                $slotForCell,
                $frame,
                $options
            );
            $ctx->runtime->vm()->assignUnserializeProperty($entry, (string) $name, $value, $frame);
        }

        return $objectVar;
    }

    /**
     * php-src ext/date — nested DateTime / DateInterval / DateTimeZone / DatePeriod O: bags (#22447).
     *
     * Generic property copy leaves Zend wire keys (`date`, `timezone_type`, …) on the object
     * without {@see DateTimeSupport::markDateTimeLikeInitialized}, so format()/foreach fatals.
     *
     * @param array<int, Variable>      $canonical
     * @param array<int, Variable>      $slotForCell
     * @param array<string, mixed>|null $options
     */
    private static function tryRestoreDateExtensionObject(
        Context $ctx,
        string $lc,
        VmUnserializeObjectPayload $payload,
        array &$canonical,
        array &$slotForCell,
        ?Frame $frame,
        int $id,
        ?array $options = null
    ): ?Variable {
        if (DateTimeSupport::CLASS_DATETIME === $lc || DateTimeSupport::CLASS_DATETIMEIMMUTABLE === $lc) {
            $data = self::objectPayloadToPhpArray($ctx, $payload, $canonical, $slotForCell, $frame, $options);
            $restored = DateTimeSupport::restoreFromZendSerialize($ctx, $lc, $data);

            return self::registerRestoredObject($restored, $canonical, $slotForCell, $id);
        }
        if (DateTimeSupport::CLASS_DATETIMEZONE === $lc) {
            $data = self::objectPayloadToPhpArray($ctx, $payload, $canonical, $slotForCell, $frame, $options);
            $restored = DateTimeSupport::restoreTimezoneFromZendSerialize($ctx, $data);

            return self::registerRestoredObject($restored, $canonical, $slotForCell, $id);
        }
        if (DateIntervalSupport::CLASS_DATEINTERVAL === $lc) {
            $data = self::objectPayloadToPhpArray($ctx, $payload, $canonical, $slotForCell, $frame, $options);
            $restored = DateIntervalSupport::restoreFromZendSerialize($ctx, $data);
            if (null === $restored) {
                throw new \Error('Invalid serialization data for DateInterval object');
            }

            return self::registerRestoredObject($restored, $canonical, $slotForCell, $id);
        }
        if (DatePeriodSupport::CLASS_DATEPERIOD === $lc) {
            $ht = new HashTable();
            foreach ($payload->properties as $name => $child) {
                \assert($child instanceof VmUnserializeCell);
                $slot = self::cellToVariableWithContext(
                    $ctx,
                    $child,
                    $canonical,
                    $slotForCell,
                    $frame,
                    $options
                );
                if (\is_int($name)) {
                    $ht->addIndex($name, $slot);
                } else {
                    $ht->add((string) $name, $slot);
                }
            }
            $bag = new Variable();
            $bag->array($ht);
            $restored = DatePeriodSupport::restoreFromSetStateHash($ctx, $bag);

            return self::registerRestoredObject($restored, $canonical, $slotForCell, $id);
        }

        return null;
    }

    /**
     * @param array<int, Variable>      $canonical
     * @param array<int, Variable>      $slotForCell
     * @param array<string, mixed>|null $options
     *
     * @return array<string, mixed>
     */
    private static function objectPayloadToPhpArray(
        Context $ctx,
        VmUnserializeObjectPayload $payload,
        array &$canonical,
        array &$slotForCell,
        ?Frame $frame,
        ?array $options = null
    ): array {
        $data = [];
        foreach ($payload->properties as $name => $child) {
            \assert($child instanceof VmUnserializeCell);
            $slot = self::cellToVariableWithContext(
                $ctx,
                $child,
                $canonical,
                $slotForCell,
                $frame,
                $options
            );
            $data[(string) $name] = self::variableToPhpScalar($slot->resolveIndirect());
        }

        return $data;
    }

    private static function variableToPhpScalar(Variable $var): mixed
    {
        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_INTEGER => $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            Variable::TYPE_STRING => $var->toString(),
            default => throw new \LogicException(
                'unserialize() date wire property type not supported in this compiler build'
            ),
        };
    }

    /**
     * @param array<int, Variable> $canonical
     * @param array<int, Variable> $slotForCell
     */
    private static function registerRestoredObject(
        ObjectEntry $restored,
        array &$canonical,
        array &$slotForCell,
        int $id
    ): Variable {
        $objectVar = new Variable();
        $objectVar->object($restored);
        $canonical[$id] = $objectVar;
        $slotForCell[$id] = $objectVar;

        return $objectVar;
    }

    /**
     * @param array<int|string, VmUnserializeCell> $cells
     */
    private static function isListCellMap(array $cells): bool
    {
        $i = 0;
        foreach ($cells as $key => $_) {
            if ($key !== $i) {
                return false;
            }
            ++$i;
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $seen
     */
    private static function &materializeCell(VmUnserializeCell $cell, array &$seen = []): mixed
    {
        $id = spl_object_id($cell);
        if (isset($seen[$id])) {
            return $seen[$id];
        }

        if (\is_array($cell->value)) {
            $array = [];
            $seen[$id] = &$array;
            foreach ($cell->value as $key => $child) {
                \assert($child instanceof VmUnserializeCell);
                $array[$key] = &self::materializeCell($child, $seen);
            }

            return $array;
        }

        $seen[$id] = $cell->value;

        return $seen[$id];
    }

    private function expect(string $literal): bool
    {
        $len = \strlen($literal);
        if ($this->pos + $len > $this->length) {
            return $this->fail();
        }
        if ($literal !== \substr($this->payload, $this->pos, $len)) {
            return $this->fail();
        }
        $this->pos += $len;

        return true;
    }

    private function readDigit(): ?int
    {
        if ($this->pos >= $this->length || !\ctype_digit($this->payload[$this->pos])) {
            return null;
        }
        $digit = (int) $this->payload[$this->pos];
        ++$this->pos;

        return $digit;
    }

    private function readUnsignedInteger(): ?int
    {
        if ($this->pos >= $this->length || !\ctype_digit($this->payload[$this->pos])) {
            return null;
        }
        $start = $this->pos;
        while ($this->pos < $this->length && \ctype_digit($this->payload[$this->pos])) {
            ++$this->pos;
        }
        $digits = \substr($this->payload, $start, $this->pos - $start);
        if ('' === $digits) {
            return null;
        }

        return (int) $digits;
    }

    private function readSignedInteger(): ?int
    {
        if ($this->pos >= $this->length) {
            return null;
        }
        $negative = false;
        if ('-' === $this->payload[$this->pos]) {
            $negative = true;
            ++$this->pos;
        }
        if ($this->pos >= $this->length || !\ctype_digit($this->payload[$this->pos])) {
            return null;
        }
        $start = $this->pos;
        while ($this->pos < $this->length && \ctype_digit($this->payload[$this->pos])) {
            ++$this->pos;
        }
        $digits = \substr($this->payload, $start, $this->pos - $start);
        if ('' === $digits) {
            return null;
        }
        // php-src var_unserializer.re / zend_long.h — |digits| may be 2^63; host (int) clamps (#23689).
        if ($negative && '9223372036854775808' === $digits) {
            return PHP_INT_MIN;
        }
        $unsigned = (int) $digits;

        return $negative ? -$unsigned : $unsigned;
    }

    private function readStringContent(int $byteLength): ?string
    {
        if ($byteLength < 0) {
            return null;
        }
        $content = '';
        $consumed = 0;
        while ($consumed < $byteLength && $this->pos < $this->length) {
            $ch = $this->payload[$this->pos];
            if ('\\' === $ch) {
                if ($this->pos + 1 >= $this->length) {
                    return null;
                }
                $next = $this->payload[$this->pos + 1];
                // php-src var_unserializer.re — only \" and \\ are escapes; \E in O: class names stays literal (#13476).
                if ('"' === $next || '\\' === $next) {
                    $content .= $next;
                    $this->pos += 2;
                    $consumed += 1;

                    continue;
                }
                $content .= '\\';
                ++$this->pos;
                $consumed += 1;

                continue;
            }
            $content .= $ch;
            ++$this->pos;
            $consumed += 1;
        }
        if ($consumed !== $byteLength) {
            return null;
        }

        return $content;
    }
}

/** Identity cell for php-src var_hash / R: markers (var_unserializer.re, #12080). */
final class VmUnserializeCell
{
    /** @var array<int|string, VmUnserializeCell>|bool|float|int|null|string|VmUnserializeObjectPayload|VmUnserializeRootObject */
    public mixed $value = null;
}

/** O: payload captured during parse (materialized with Context). */
final class VmUnserializeObjectPayload
{
    /**
     * @param array<int|string, VmUnserializeCell> $properties
     */
    public function __construct(
        public readonly string $className,
        public readonly array $properties,
        public readonly int $start,
        public readonly int $end,
    ) {
    }
}

/** E: payload captured during parse — enum wire format (#23692). */
final class VmUnserializeEnumPayload
{
    public function __construct(
        public readonly string $payload,
    ) {
    }
}

/** Placeholder for r:1 object-under-construction (#12082). */
final class VmUnserializeRootObject
{
    public function __construct(public readonly Variable $objectVar)
    {
    }
}
