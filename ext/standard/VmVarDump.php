<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;

/**
 * var_dump() formatting SSOT (ext/standard/var.c parity, #9195).
 *
 * Shared by VM {@see var_dump_} and JIT {@see VarDumpJitHelper}.
 */
final class VmVarDump
{
    public static function dumpVariable(
        VM $vm,
        Variable $var,
        int $level = 1,
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
        if ($level > 1) {
            // Avoid \str_repeat — NestedJIT of this SSOT may lack __compiler_str_repeat (#23540 / peer #22981).
            self::write(self::spaces($level - 1));
        }
        // php-src php_var_dump: unwrap IS_REFERENCE first, but PUTS("*RECURSION*") skips COMMON (&).
        // Resolve before writing '&' so circular refs print "*RECURSION*" not "&*RECURSION*" (#28795).
        $isRef = false;
        if ($showRefMarker && Variable::TYPE_INDIRECT === $var->type) {
            $isRef = true;
            $var = $var->resolveIndirect();
        }
        if (self::tryWriteScalarPayload($var, $isRef)) {
            return;
        }
        $resourceDump = VmVarFormat::tryFormatVarDump($var);
        if (null !== $resourceDump) {
            if ($isRef) {
                self::write('&');
            }
            self::write($resourceDump);

            return;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            self::dumpArray($vm, $var->toArray(), $level, $frame, $visited, $isRef);

            return;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            self::dumpObject($vm, $var->toObject(), $level, $frame, $visited, $isRef);

            return;
        }
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            if ($isRef) {
                self::write('&');
            }
            $case = $var->toEnumCase();
            self::write('enum('.$case->enumClass->name.'::'.$case->caseName.")\n");

            return;
        }

        if ($isRef) {
            self::write('&');
        }
        self::write("unknown()\n");
    }

    /**
     * Scalar/null dump without Runtime->vm (#23540).
     *
     * Thin standalone AOT NestedJIT of VarDumpJitHelper segfaults on
     * `$ctx->runtime->vm` (class-id layout vs consumer). Int/float/bool/null/string
     * arms never use $vm — dump them before touching Context.
     *
     * @return bool true when the value was fully dumped
     */
    public static function tryDumpWithoutVm(Variable $var, int $level = 1, bool $showRefMarker = false): bool
    {
        TypedPropertyCheck::assertReadable($var);
        if ($level > 1) {
            self::write(self::spaces($level - 1));
        }
        $isRef = false;
        if ($showRefMarker && Variable::TYPE_INDIRECT === $var->type) {
            $isRef = true;
            $var = $var->resolveIndirect();
        }

        return self::tryWriteScalarPayload($var, $isRef);
    }

    /** @return bool true when $var is a scalar/null arm that was written */
    private static function tryWriteScalarPayload(Variable $var, bool $isRef = false): bool
    {
        if (Variable::TYPE_INTEGER === $var->type) {
            if ($isRef) {
                self::write('&');
            }
            self::write('int('.$var->toInt().")\n");

            return true;
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            if ($isRef) {
                self::write('&');
            }
            self::write('float('.VmFloatDtoa::formatVarDump($var->toFloat()).")\n");

            return true;
        }
        if (Variable::TYPE_STRING === $var->type) {
            if ($isRef) {
                self::write('&');
            }
            self::write('string('.\strlen($var->toString()).') "'.$var->toString()."\"\n");

            return true;
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            if ($isRef) {
                self::write('&');
            }
            self::write('bool('.($var->toBool() ? 'true' : 'false').")\n");

            return true;
        }
        if (Variable::TYPE_NULL === $var->type) {
            if ($isRef) {
                self::write('&');
            }
            self::write("NULL\n");

            return true;
        }

        return false;
    }

    private static function write(string $chunk): void
    {
        OutputBuffer::append($chunk);
    }

    /**
     * @param \SplObjectStorage<object, true> $visited
     */
    private static function dumpArray(
        VM $vm,
        VM\HashTable $table,
        int $level,
        ?Frame $frame,
        \SplObjectStorage $visited,
        bool $isRef = false
    ): void {
        // php-src ext/standard/var.c — GC_IS_RECURSIVE → PUTS("*RECURSION*") without COMMON (&) (#28795).
        if ($visited->contains($table)) {
            self::write("*RECURSION*\n");

            return;
        }
        if ($isRef) {
            self::write('&');
        }
        $visited->attach($table);
        try {
            $count = 0;
            foreach ($table->iterateKeyed(false) as $_) {
                ++$count;
            }
            self::write('array('.$count.") {\n");
            foreach ($table->iterateKeyed(false) as [$key, $value]) {
                // php-src php_array_element_dump: "%*c" with level+1; recurse level+2 (#23726).
                self::write(self::spaces($level + 1));
                self::write(self::formatKey($key)."\n");
                self::dumpNested($vm, $value, $level + 2, true, $frame, $visited);
            }
            if ($level > 1) {
                self::write(self::spaces($level - 1));
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
        \SplObjectStorage $visited,
        bool $isRef = false
    ): void {
        if (EnumCaseSupport::isEnumCase($object)) {
            if ($isRef) {
                self::write('&');
            }
            self::write('enum('.$object->class->name.'::'.($object->enumCaseName ?? '').")\n");

            return;
        }
        // php-src Z_IS_RECURSIVE_P → PUTS("*RECURSION*") without COMMON (&) (#28795).
        if ($visited->contains($object)) {
            self::write("*RECURSION*\n");

            return;
        }
        if ($isRef) {
            self::write('&');
        }
        $visited->attach($object);
        try {
            $props = $object->getProperties(ClassEntry::PROP_PURPOSE_DEBUG, $vm, $frame);
            // php-src php_var_dump object count: initialized props only; uninit typed still dumped (#31147).
            $count = 0;
            foreach ($props as $value) {
                if (!TypedPropertyCheck::isUninitializedDebugSlot($value)) {
                    ++$count;
                }
            }
            $className = VmObjectDebugType::fromClassName($object->class->name);
            self::write('object('.$className.')#'.$object->id.' ('.$count.") {\n");
            foreach ($props as $name => $value) {
                // php-src php_object_property_dump: "%*c" with level+1; recurse level+2 (#23726).
                self::write(self::spaces($level + 1));
                self::write(VmDebugPropertyName::formatForVarDump($name)."=>\n");
                if (TypedPropertyCheck::isUninitializedDebugSlot($value)) {
                    // Do not assertReadable — Zend prints uninitialized(T) (#31147, ext/standard/var.c).
                    // Indent matches dumpNested($level+2): spaces(($level+2)-1) = spaces($level+1).
                    self::write(self::spaces($level + 1));
                    self::write('uninitialized('.TypedPropertyCheck::uninitializedTypeString($value).")\n");

                    continue;
                }
                self::dumpNested($vm, $value, $level + 2, true, $frame, $visited);
            }
            if ($level > 1) {
                self::write(self::spaces($level - 1));
            }
            self::write("}\n");
        } finally {
            $visited->detach($object);
        }
    }

    /** NestedJIT-safe spaces without \str_repeat (#23540 / peer PackEngineEncode #22981). */
    private static function spaces(int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $out = '';
        while ($n-- > 0) {
            $out .= ' ';
        }

        return $out;
    }

    private static function formatKey(Variable $key): string
    {
        if (Variable::TYPE_INTEGER === $key->type) {
            return '['.$key->toInt().']=>';
        }

        return '["'.$key->toString().'"]=>';
    }
}
