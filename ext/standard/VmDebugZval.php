<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;

/**
 * debug_zval_dump() formatting SSOT (ext/standard/php_debug.c parity, #4709).
 *
 * Shared by VM {@see debug_zval_dump} and future JIT refcount emitter.
 */
final class VmDebugZval
{
    public static function dumpVariable(
        VM $vm,
        Variable $var,
        int $level = 0,
        bool $showRefMarker = false,
        ?Frame $frame = null
    ): void {
        /** @var \SplObjectStorage<object, true> $visited */
        $visited = new \SplObjectStorage();
        self::dumpNested($vm, $var, $level, $showRefMarker, $frame, $visited);
    }

    /**
     * @param \SplObjectStorage<object, true> $visited
     */
    private static function dumpNested(
        VM $vm,
        Variable $var,
        int $level,
        bool $showRefMarker,
        ?Frame $frame,
        \SplObjectStorage $visited
    ): void {
        TypedPropertyCheck::assertReadable($var);
        if ($showRefMarker) {
            if (Variable::TYPE_INDIRECT === $var->type) {
                $target = $var->directIndirectTarget();
                if (null === $target) {
                    self::write(self::indent($level)."NULL\n");

                    return;
                }
                $var = $target;
            }
            $aliasCount = self::countReferenceAliases($vm, $var);
            if ($aliasCount > 0) {
                $refcount = $aliasCount + 1;
                self::write(self::indent($level).'reference refcount('.$refcount.") {\n");
                self::dumpNested($vm, $var, $level + 1, false, $frame, $visited);
                self::write(self::indent($level)."}\n");

                return;
            }
        }
        if ($level > 0) {
            self::write(self::indent($level));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            self::write('int('.$var->toInt().")\n");

            return;
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            self::write('float('.VmFloatDtoa::formatVarDump($var->toFloat()).")\n");

            return;
        }
        if (Variable::TYPE_STRING === $var->type) {
            // php-src Zend/zend_builtin_functions.c / ext/standard/var.c — ZSTR_IS_INTERNED (#22716)
            $suffix = $var->stringInterned ? ' interned' : '';
            self::write('string('.\strlen($var->toString()).') "'.$var->toString().'"'.$suffix."\n");

            return;
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            self::write('bool('.($var->toBool() ? 'true' : 'false').")\n");

            return;
        }
        if (Variable::TYPE_NULL === $var->type) {
            self::write("NULL\n");

            return;
        }
        $resourceDump = VmVarFormat::tryFormatDebugZvalDump($vm, $var);
        if (null !== $resourceDump) {
            self::write($resourceDump);

            return;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            self::dumpArray($vm, $var->toArray(), $level, $frame, $visited);

            return;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            self::dumpObject($vm, $var->toObject(), $level, $frame, $visited);

            return;
        }
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            $case = $var->toEnumCase();
            self::write('enum('.$case->enumClass->name.'::'.$case->caseName.")\n");

            return;
        }

        self::write("unknown()\n");
    }

    /**
     * Zend zend_debug_zval() reference refcount — count indirect wrappers sharing storage.
     */
    public static function countReferenceAliases(VM $vm, Variable $refCell): int
    {
        $targetId = \spl_object_id($refCell);
        $count = 0;
        $varSeen = [];
        $containerSeen = [];

        $walk = static function (Variable $var) use (&$walk, &$count, $targetId, &$varSeen, &$containerSeen): void {
            $varId = \spl_object_id($var);
            if (isset($varSeen[$varId])) {
                return;
            }
            $varSeen[$varId] = true;

            if (Variable::TYPE_INDIRECT === $var->type) {
                $target = $var->directIndirectTarget();
                if (null !== $target && \spl_object_id($target) === $targetId) {
                    ++$count;
                }

                return;
            }

            if (Variable::TYPE_ARRAY === $var->type) {
                $array = $var->toArray();
                $arrayId = \spl_object_id($array);
                if (isset($containerSeen['a'.$arrayId])) {
                    return;
                }
                $containerSeen['a'.$arrayId] = true;
                foreach ($array->iterate(false) as $element) {
                    $walk($element);
                }

                return;
            }

            if (Variable::TYPE_OBJECT === $var->type) {
                $object = $var->toObject();
                $objId = $object->id;
                if (isset($containerSeen['o'.$objId])) {
                    return;
                }
                $containerSeen['o'.$objId] = true;
                foreach ($object->propertiesWithNames() as $prop) {
                    $walk($prop);
                }
            }
        };

        $vm->visitStrongRefRoots($walk);

        return $count;
    }

    /**
     * Count strong Variable slots referencing an object — Zend GC_REFCOUNT for debug_zval_dump (#18419).
     *
     * Skips in-flight builtin handler arg temps so internal calls do not inflate refcount display.
     */
    public static function countObjectAliases(VM $vm, ObjectEntry $object): int
    {
        $targetId = $object->id;
        $count = 0;
        $varSeen = [];

        $walk = static function (Variable $var) use (&$walk, &$count, $targetId, &$varSeen): void {
            $var = $var->resolveIndirect();
            $varId = \spl_object_id($var);
            if (isset($varSeen[$varId])) {
                return;
            }
            $varSeen[$varId] = true;

            if (Variable::TYPE_OBJECT === $var->type) {
                try {
                    if ($var->toObject()->id === $targetId) {
                        ++$count;
                    }
                } catch (\LogicException) {
                }

                return;
            }

            if (Variable::TYPE_ARRAY === $var->type) {
                foreach ($var->toArray()->iterate(false) as $element) {
                    $walk($element);
                }
            }
        };

        $vm->visitStrongRefRoots($walk, false);

        return $count;
    }

    /**
     * @param \SplObjectStorage<object, true> $visited
     */
    private static function dumpArray(
        VM $vm,
        VM\HashTable $table,
        int $level,
        ?Frame $frame,
        \SplObjectStorage $visited
    ): void {
        // php-src php_debug_zval_dump — GC_IS_RECURSIVE → PUTS("*RECURSION*") (#28795).
        if ($visited->contains($table)) {
            self::write("*RECURSION*\n");

            return;
        }
        $visited->attach($table);
        try {
            $count = 0;
            foreach ($table->iterateKeyed(false) as $_) {
                ++$count;
            }
            self::write('array('.$count.') refcount('.$table->getGcRefcount()."){\n");
            foreach ($table->iterateKeyed(false) as [$key, $value]) {
                self::write(self::indent($level + 1));
                self::write(self::formatKey($key)."\n");
                self::dumpNested($vm, $value, $level + 1, true, $frame, $visited);
            }
            if ($level > 0) {
                self::write(self::indent($level));
            }
            self::write("}\n");
        } finally {
            $visited->detach($table);
        }
    }

    /**
     * @param \SplObjectStorage<object, true> $visited
     */
    private static function dumpObject(
        VM $vm,
        VM\ObjectEntry $object,
        int $level,
        ?Frame $frame,
        \SplObjectStorage $visited
    ): void {
        if (EnumCaseSupport::isEnumCase($object)) {
            self::write('enum('.$object->class->name.'::'.($object->enumCaseName ?? '').")\n");

            return;
        }
        // php-src Z_IS_RECURSIVE_P → PUTS("*RECURSION*") (#28795).
        if ($visited->contains($object)) {
            self::write("*RECURSION*\n");

            return;
        }
        $visited->attach($object);
        try {
            $props = $object->getProperties(ClassEntry::PROP_PURPOSE_DEBUG, $vm, $frame);
            // Same initialized-only count as var_dump (#31147 / #31165).
            $count = 0;
            foreach ($props as $value) {
                if (!TypedPropertyCheck::isUninitializedDebugSlot($value)) {
                    ++$count;
                }
            }
            $className = VmObjectDebugType::fromClassName($object->class->name);
            self::write('object('.$className.')#'.$object->id.' ('.$count.') refcount('.$object->refCount."){\n");
            foreach ($props as $name => $value) {
                self::write(self::indent($level + 1));
                self::write(VmDebugPropertyName::formatForVarDump($name)."=>\n");
                if (TypedPropertyCheck::isUninitializedDebugSlot($value)) {
                    self::write(self::indent($level + 1));
                    self::write('uninitialized('.TypedPropertyCheck::uninitializedTypeString($value).")\n");

                    continue;
                }
                self::dumpNested($vm, $value, $level + 1, true, $frame, $visited);
            }
            if ($level > 0) {
                self::write(self::indent($level));
            }
            self::write("}\n");
        } finally {
            $visited->detach($object);
        }
    }

    private static function formatKey(Variable $key): string
    {
        if (Variable::TYPE_INTEGER === $key->type) {
            return '['.$key->toInt().']=>';
        }

        return '["'.$key->toString().'"]=>';
    }

    private static function indent(int $level): string
    {
        return $level > 0 ? \str_repeat(' ', $level * 2) : '';
    }

    private static function write(string $chunk): void
    {
        OutputBuffer::append($chunk);
    }
}
