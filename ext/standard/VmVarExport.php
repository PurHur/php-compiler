<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;

/**
 * var_export() formatting SSOT (ext/standard/var.c parity, #9189).
 *
 * Shared by VM {@see var_export} and JIT {@see VarExportJitHelper}.
 */
final class VmVarExport
{
    private const CIRCULAR_WARNING = 'var_export does not handle circular references';

    public static function formatVariable(
        VM $vm,
        Variable $v,
        int $level = 0,
        ?Frame $frame = null
    ): string {
        /** @var \SplObjectStorage<int, true> $visited */
        $visited = new \SplObjectStorage();
        $warned = false;

        return self::formatNested($vm, $v, $level, $frame, $visited, $warned);
    }

    /**
     * @param \SplObjectStorage<int, true> $visited
     */
    private static function formatNested(
        VM $vm,
        Variable $v,
        int $level,
        ?Frame $frame,
        \SplObjectStorage $visited,
        bool &$warned
    ): string {
        $v = $v->resolveIndirect();
        // php-src var.c: live and closed resources export as NULL (#5148, #11421).
        if (ResourceSupport::isVmResource($v)) {
            return 'NULL';
        }
        // php-src var.c: stream contexts are resources but var_export prints NULL (#10704).
        if (VmStreamContext::isRepresentation($v)) {
            return 'NULL';
        }
        if (Variable::TYPE_BOOLEAN === $v->type) {
            return $v->toBool() ? 'true' : 'false';
        }
        if (Variable::TYPE_UNDEFINED === $v->type) {
            TypedPropertyCheck::assertReadable($v);

            return 'NULL';
        }
        if (Variable::TYPE_NULL === $v->type) {
            return 'NULL';
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            return self::formatExportInteger($v->toInt());
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return VmVarExportFloat::format($v->toFloat());
        }
        if (Variable::TYPE_STRING === $v->type) {
            return self::formatExportString($v->toString());
        }
        if (Variable::TYPE_ARRAY === $v->type) {
            $ht = $v->toArray();
            if ($visited->contains($ht)) {
                self::warnCircular($frame, $warned);

                return 'NULL';
            }
            $visited->attach($ht);
            try {
                return self::formatArray($vm, $ht, $level, $frame, $visited, $warned);
            } finally {
                $visited->detach($ht);
            }
        }
        if (Variable::TYPE_ENUM_CASE === $v->type) {
            $case = $v->toEnumCase();

            return self::formatEnumCaseLiteral($case->enumClass->name, $case->caseName);
        }
        if (Variable::TYPE_OBJECT === $v->type) {
            return self::formatObject($vm, $v, $level, $frame, $visited, $warned);
        }

        throw new \LogicException('var_export() does not support this value type in this compiler build');
    }

    /**
     * php-src var.c php_var_export_ex — PHP_INT_MIN is not a valid integer literal
     * (parser overflow), so Zend emits {@code (PHP_INT_MIN+1).'-1'} (#23690).
     */
    private static function formatExportInteger(int $value): string
    {
        if (\PHP_INT_MIN === $value) {
            return (string) (\PHP_INT_MIN + 1).'-1';
        }

        return (string) $value;
    }

    /**
     * Zend var_export() for enum cases: {@code \EnumName::Case} (zend_enum.c / var.c).
     */
    private static function formatEnumCaseLiteral(string $enumClassName, string $caseName): string
    {
        return '\\'.ltrim($enumClassName, '\\').'::'.$caseName;
    }

    /** php-src var.c — enum case elements break onto the next indented line after {@code =>}. */
    private static function exportValueNeedsLineBreakAfterArrow(Variable $v): bool
    {
        if (Variable::TYPE_ENUM_CASE === $v->type) {
            return true;
        }
        if (Variable::TYPE_OBJECT === $v->type && EnumCaseSupport::isEnumCase($v->toObject())) {
            return true;
        }

        return false;
    }

    /**
     * @param \SplObjectStorage<int, true> $visited
     */
    private static function formatObject(
        VM $vm,
        Variable $v,
        int $level,
        ?Frame $frame,
        \SplObjectStorage $visited,
        bool &$warned
    ): string {
        if (null === $frame) {
            throw new \LogicException('var_export() object branch requires an active frame (#9189)');
        }
        $object = $v->resolveIndirect()->toObject();
        if ($visited->contains($object)) {
            self::warnCircular($frame, $warned);

            return 'NULL';
        }
        $visited->attach($object);
        try {
            if (EnumCaseSupport::isEnumCase($object)) {
                return self::formatEnumCaseLiteral($object->class->name, $object->enumCaseName ?? '');
            }
            $className = $object->class->name;
            $props = VmReflection::getVarExportObjectProperties($v, $frame);
            // php-src var.c: __set_state / (object) use compact "array(" not "array (".
            $exported = self::formatArray($vm, $props->toArray(), $level, $frame, $visited, $warned, true);
            if ('stdClass' === $className) {
                return '(object) '.$exported;
            }

            return '\\'.ltrim($className, '\\').'::__set_state('.$exported.')';
        } finally {
            $visited->detach($object);
        }
    }

    /**
     * @param \SplObjectStorage<int, true> $visited
     */
    private static function formatArray(
        VM $vm,
        HashTable $ht,
        int $level,
        ?Frame $frame,
        \SplObjectStorage $visited,
        bool &$warned,
        bool $compactHeader = false
    ): string {
        $indent = str_repeat('  ', $level);
        $inner = str_repeat('  ', $level + 1);
        $lines = [$compactHeader ? "array(\n" : "array (\n"];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $k = Variable::TYPE_INTEGER === $key->type
                ? (string) $key->toInt()
                : self::formatExportStringKey($key->toString());
            $resolved = $value->resolveIndirect();
            $formatted = self::formatNested(
                $vm,
                $resolved,
                $level + 1,
                $frame,
                $visited,
                $warned
            );
            if (self::exportValueNeedsLineBreakAfterArrow($resolved)) {
                $lines[] = $inner.$k.' => '."\n".$inner.$formatted.",\n";
            } else {
                $lines[] = $inner.$k.' => '.$formatted.",\n";
            }
        }
        $lines[] = $indent.')';

        return implode('', $lines);
    }

    /**
     * php-src var.c php_addcslashes — embedded NUL becomes concatenation form.
     */
    private static function formatExportString(string $str): string
    {
        $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], $str);
        if (str_contains($escaped, "\0")) {
            $escaped = str_replace("\0", "' . \"\\0\" . '", $escaped);
        }

        return "'".$escaped."'";
    }

    /**
     * php-src var.c php_array_element_export — addcslashes then NUL → concatenation form.
     */
    private static function formatExportStringKey(string $key): string
    {
        return self::formatExportString($key);
    }

    private static function warnCircular(?Frame $frame, bool &$warned): void
    {
        if ($warned || null === $frame || null === $frame->vmContext) {
            return;
        }
        $warned = true;
        $frame->vmContext->errors->triggerError(
            self::CIRCULAR_WARNING,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
