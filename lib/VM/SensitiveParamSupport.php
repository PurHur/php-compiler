<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\SensitiveParameterValueConstruct;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Builtin\SensitiveParameterValueDebugInfo;
use PHPCompiler\VM\Builtin\SensitiveParameterValueGetValue;
use PHPCompiler\ext\standard\VmIni;
use PHPCfg\Func;

/**
 * #[\SensitiveParameter] trace redaction (PHP 8.2, Zend zend_builtin_functions.c, issue #3351).
 */
final class SensitiveParamSupport
{
    public const CLASS_NAME = 'SensitiveParameterValue';

    public const PROP_VALUE = 'value';

    public const TRACE_ARG_LABEL = '[Sensitive Parameter]';

    /** Mirrors {@see \PHPCompiler\ext\standard\VmDebugBacktrace::IGNORE_ARGS}. */
    public const BACKTRACE_IGNORE_ARGS = 2;

    /** Mirrors {@see \PHPCompiler\ext\standard\VmDebugBacktrace::PROVIDE_OBJECT}. */
    public const BACKTRACE_PROVIDE_OBJECT = 1;

    /** Class entry from {@see register()} — shared by wrapValue/createMarker (#22487). */
    private static ?ClassEntry $registeredEntry = null;

    public static function register(Context $ctx): void
    {
        $entry = self::buildClassEntry();
        self::$registeredEntry = $entry;
        $ctx->classes[strtolower(self::CLASS_NAME)] = $entry;
    }

    public static function requireMarkerObject(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('SensitiveParameterValue method called without object');
        }
        $obj = $receiver->toObject();
        if (strtolower($obj->class->name) !== strtolower(self::CLASS_NAME)) {
            throw new \LogicException('Expected SensitiveParameterValue instance');
        }

        return $obj;
    }

    public static function wrapValue(Variable $value): Variable
    {
        $value = $value->resolveIndirect();
        $obj = new ObjectEntry(self::markerClassEntry());
        $obj->constructed = true;
        $obj->getProperty(self::PROP_VALUE)->copyFrom($value);
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object($obj);

        return $out;
    }

    /** Unwrap SensitiveParameterValue for reflection/introspection (#5127). */
    public static function unwrapForReflection(Variable $value): Variable
    {
        $value = $value->resolveIndirect();
        if (!self::isMarker($value)) {
            $out = new Variable();
            $out->copyFrom($value);

            return $out;
        }
        $obj = $value->toObject();
        $stored = $obj->getProperty(self::PROP_VALUE)->resolveIndirect();
        if (Variable::TYPE_NULL === $stored->type) {
            $out = new Variable();
            $out->copyFrom($value);

            return $out;
        }
        $out = new Variable();
        $out->copyFrom($stored);

        return $out;
    }

    public static function createMarker(): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object(new ObjectEntry(self::markerClassEntry()));

        return $var;
    }

    public static function isMarker(Variable $value): bool
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $value->type) {
            return false;
        }

        return strtolower($value->toObject()->class->name) === strtolower(self::CLASS_NAME);
    }

    /** @param array<int, true> $sensitive compile-time #[\SensitiveParameter] map from Block::paramSensitive */
    public static function compileTimeParamIsSensitive(array $sensitive, int $paramIdx): bool
    {
        return isset($sensitive[$paramIdx]);
    }

    /**
     * Packed list of call arguments for debug_backtrace / getTrace frames.
     */
    public static function buildArgsArray(Frame $frame): ?Variable
    {
        if ([] === $frame->calledArgs || null === $frame->block) {
            return null;
        }

        $sensitive = $frame->block->paramSensitive;
        if ([] === $sensitive) {
            return self::copyArgsList($frame);
        }

        $thisOffset = self::calledArgThisOffset($frame);
        $out = new Variable();
        $out->newArray();
        $ht = $out->toArray();
        $paramCount = count($frame->block->paramNames);
        for ($paramIdx = 0; $paramIdx < $paramCount; ++$paramIdx) {
            $argIdx = $thisOffset + $paramIdx;
            if (!array_key_exists($argIdx, $frame->calledArgs)) {
                continue;
            }
            if (self::compileTimeParamIsSensitive($sensitive, $paramIdx)) {
                // Zend: wrap the real arg in SensitiveParameterValue (methods + getValue) (#22487).
                $ht->append(self::wrapValue($frame->calledArgs[$argIdx]));

                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$argIdx]->resolveIndirect());
            $ht->append($copy);
        }

        return $out;
    }

    /**
     * Format one trace argument for Throwable::getTraceAsString() (Zend zend_exceptions.c).
     *
     * SensitiveParameterValue markers format as Object(SensitiveParameterValue) — same as any
     * object — not the debug_print_backtrace flat label {@see TRACE_ARG_LABEL} (#21524).
     *
     * String args use single quotes, smart_str_append_escaped + zend.exception_string_param_max_len
     * truncation (#21999). getTrace() raw values are unchanged.
     */
    public static function formatTraceArg(Variable $arg): string
    {
        $arg = $arg->resolveIndirect();
        switch ($arg->type) {
            case Variable::TYPE_STRING:
                return self::formatTraceStringArg($arg->toString());
            case Variable::TYPE_NULL:
                return 'NULL';
            case Variable::TYPE_BOOLEAN:
                return $arg->toBool() ? 'true' : 'false';
            case Variable::TYPE_INTEGER:
                return (string) $arg->toInt();
            case Variable::TYPE_FLOAT:
                return (string) $arg->toFloat();
            case Variable::TYPE_OBJECT:
                return 'Object('.($arg->toObject()->class->name).')';
            case Variable::TYPE_ARRAY:
                return 'Array';
            default:
                return $arg->toString();
        }
    }

    /**
     * php-src smart_str_append_scalar / smart_str_append_escaped_truncated
     * (Zend/zend_smart_str.c, EG(exception_string_param_max_len)).
     */
    private static function formatTraceStringArg(string $value): string
    {
        $maxLen = VmIni::getExceptionStringParamMaxLen();
        $len = \strlen($value);
        $slice = $len > $maxLen ? \substr($value, 0, $maxLen) : $value;
        $out = "'".self::escapeTraceStringBytes($slice);
        if ($len > $maxLen) {
            $out .= '...';
        }

        return $out."'";
    }

    /** php-src smart_str_append_escaped (Zend/zend_smart_str.c). */
    private static function escapeTraceStringBytes(string $s): string
    {
        $out = '';
        $n = \strlen($s);
        for ($i = 0; $i < $n; ++$i) {
            $c = \ord($s[$i]);
            if (32 <= $c && 126 >= $c && 92 !== $c) {
                $out .= $s[$i];
                continue;
            }
            $out .= '\\';
            switch ($c) {
                case 10:
                    $out .= 'n';
                    break;
                case 13:
                    $out .= 'r';
                    break;
                case 9:
                    $out .= 't';
                    break;
                case 12:
                    $out .= 'f';
                    break;
                case 11:
                    $out .= 'v';
                    break;
                case 92:
                    $out .= '\\';
                    break;
                case 27:
                    $out .= 'e';
                    break;
                default:
                    $out .= 'x'.\strtoupper(\sprintf('%02x', $c));
                    break;
            }
        }

        return $out;
    }

    private static function copyArgsList(Frame $frame): Variable
    {
        $out = new Variable();
        $out->newArray();
        $ht = $out->toArray();
        foreach ($frame->calledArgs as $arg) {
            $copy = new Variable();
            $copy->copyFrom($arg->resolveIndirect());
            $ht->append($copy);
        }

        return $out;
    }

    private static function calledArgThisOffset(Frame $frame): int
    {
        $func = $frame->block->func ?? null;
        if (null === $func || null === $func->class) {
            return 0;
        }
        if (($func->flags ?? 0) & Func::FLAG_STATIC) {
            return 0;
        }

        return 1;
    }

    private static function markerClassEntry(): ClassEntry
    {
        if (null !== self::$registeredEntry) {
            return self::$registeredEntry;
        }
        // Tests / early boot before BuiltinClasses::register (#22487).
        self::$registeredEntry = self::buildClassEntry();

        return self::$registeredEntry;
    }

    private static function buildClassEntry(): ClassEntry
    {
        $mixedProto = new Variable();
        $pub = \PHPCfg\Func::FLAG_PUBLIC;
        $priv = \PHPCfg\Func::FLAG_PRIVATE;
        $entry = new ClassEntry(self::CLASS_NAME);
        // Zend zend_exceptions.stub.php — final internal class; private readonly $value.
        $entry->isFinal = true;
        $entry->isInternal = true;
        // Private + readonly: json_encode / get_object_vars see no public props (#23042).
        // var_export uses a dedicated empty bag in VM::collectVarExportPropertiesForBuiltin
        // (Zend get_properties_for VAR_EXPORT returns empty — not just privacy).
        $valueProp = new ClassProperty(
            self::PROP_VALUE,
            null,
            $mixedProto,
            true,
            $priv,
            strtolower(self::CLASS_NAME)
        );
        $entry->properties[] = $valueProp;
        $entry->constructor = new SensitiveParameterValueConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['getvalue'] = new SensitiveParameterValueGetValue();
        $entry->methodVisibility['getvalue'] = $pub;
        $entry->methods['__debuginfo'] = new SensitiveParameterValueDebugInfo();
        $entry->methodVisibility['__debuginfo'] = $pub;

        return $entry;
    }
}
