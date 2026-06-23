<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmDateInterval;
use PHPCompiler\ext\standard\VmSerialize;

/**
 * Shared helpers for DateInterval VM builtins (issue #7278, php-src ext/date/php_date.c).
 */
final class DateIntervalSupport
{
    public const CLASS_DATEINTERVAL = 'dateinterval';

    public static function requireDateInterval(
        Variable $var,
        string $label,
        ?int $argNum = null,
        ?string $argName = null
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw self::typeError($label, $argNum, $argName, $var);
        }
        $obj = $var->toObject();
        if (self::CLASS_DATEINTERVAL !== strtolower($obj->class->name)) {
            throw self::typeError($label, $argNum, $argName, $var, $obj->class->name);
        }

        return $obj;
    }

    public static function initDateInterval(ObjectEntry $interval, string $spec): void
    {
        $parsed = VmDateInterval::parseSpec($spec);
        self::requireIntProperty($interval, 'y')->int($parsed['y']);
        self::requireIntProperty($interval, 'm')->int($parsed['m']);
        self::requireIntProperty($interval, 'd')->int($parsed['d']);
        self::requireIntProperty($interval, 'h')->int($parsed['h']);
        self::requireIntProperty($interval, 'i')->int($parsed['i']);
        self::requireIntProperty($interval, 's')->int($parsed['s']);
        self::requireFloatProperty($interval, 'f')->float($parsed['f']);
        self::requireIntProperty($interval, 'invert')->int($parsed['invert']);
        self::requireBoolProperty($interval, 'days')->bool(false);
        self::requireBoolProperty($interval, 'from_string')->bool(false);
        $interval->constructed = true;
    }

    public static function format(ObjectEntry $interval, string $format): string
    {
        return VmDateInterval::format(self::readState($interval), $format);
    }

    /**
     * @return array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int, days: bool|int}
     */
    public static function readState(ObjectEntry $interval): array
    {
        $daysVar = self::requireProperty($interval, 'days');
        $days = $daysVar->resolveIndirect();
        $daysValue = false;
        if (Variable::TYPE_INTEGER === $days->type) {
            $daysValue = $days->toInt();
        } elseif (Variable::TYPE_BOOLEAN !== $days->type || $days->toBool()) {
            throw new \LogicException('DateInterval days property is missing in this compiler build');
        }

        return [
            'y' => self::requireIntProperty($interval, 'y')->toInt(),
            'm' => self::requireIntProperty($interval, 'm')->toInt(),
            'd' => self::requireIntProperty($interval, 'd')->toInt(),
            'h' => self::requireIntProperty($interval, 'h')->toInt(),
            'i' => self::requireIntProperty($interval, 'i')->toInt(),
            's' => self::requireIntProperty($interval, 's')->toInt(),
            'f' => self::requireFloatProperty($interval, 'f')->toFloat(),
            'invert' => self::requireIntProperty($interval, 'invert')->toInt(),
            'days' => $daysValue,
        ];
    }

    private static function typeError(
        string $label,
        ?int $argNum,
        ?string $argName,
        Variable $var,
        ?string $objectClass = null
    ): \TypeError {
        $given = null !== $objectClass
            ? $objectClass
            : ReflectionSupport::valueTypeLabelPublic($var);
        if (null !== $argNum) {
            $param = null !== $argName ? " (\${$argName})" : '';

            return new \TypeError(
                "{$label}: Argument #{$argNum}{$param} must be of type DateInterval, {$given} given"
            );
        }

        return new \TypeError("{$label} must be of type DateInterval, {$given} given");
    }

    private static function requireProperty(ObjectEntry $obj, string $name): Variable
    {
        return $obj->getProperty($name);
    }

    private static function requireIntProperty(ObjectEntry $obj, string $name): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \LogicException("DateInterval property {$name} is missing in this compiler build");
        }

        return $var;
    }

    private static function requireFloatProperty(ObjectEntry $obj, string $name): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_FLOAT !== $var->type) {
            throw new \LogicException("DateInterval property {$name} is missing in this compiler build");
        }

        return $var;
    }

    private static function requireBoolProperty(ObjectEntry $obj, string $name): Variable
    {
        $var = $obj->getProperty($name)->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \LogicException("DateInterval property {$name} is missing in this compiler build");
        }

        return $var;
    }

    /**
     * @param array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int, days: bool|int} $state
     */
    public static function writeState(ObjectEntry $interval, array $state): void
    {
        self::requireIntProperty($interval, 'y')->int($state['y']);
        self::requireIntProperty($interval, 'm')->int($state['m']);
        self::requireIntProperty($interval, 'd')->int($state['d']);
        self::requireIntProperty($interval, 'h')->int($state['h']);
        self::requireIntProperty($interval, 'i')->int($state['i']);
        self::requireIntProperty($interval, 's')->int($state['s']);
        self::requireFloatProperty($interval, 'f')->float($state['f']);
        self::requireIntProperty($interval, 'invert')->int($state['invert']);
        $daysVar = self::requireProperty($interval, 'days')->resolveIndirect();
        if (\is_int($state['days'])) {
            $daysVar->int($state['days']);
        } else {
            $daysVar->bool(false);
        }
        if ($interval->hasProperty('from_string')) {
            $fromString = $interval->getProperty('from_string')->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $fromString->type) {
                $fromString->bool(false);
            }
        }
        $interval->constructed = true;
    }

    /**
     * @param array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int, days: bool|int} $state
     */
    public static function createFromState(Context $ctx, array $state): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_DATEINTERVAL] ?? null;
        if (null === $class) {
            throw new \LogicException('DateInterval is not registered in this compiler build');
        }
        $interval = new ObjectEntry($class);
        self::writeState($interval, $state);

        return $interval;
    }

    /** php-src php_date_serialize — Zend DateInterval member order (#10692). */
    public static function encodeZendSerializeWire(ObjectEntry $interval): string
    {
        $state = self::readState($interval);
        $fromString = false;
        if ($interval->hasProperty('from_string')) {
            $fs = $interval->getProperty('from_string')->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $fs->type) {
                $fromString = $fs->toBool();
            }
        }

        return VmSerialize::encodeExportedPropertyBag('DateInterval', [
            'y' => $state['y'],
            'm' => $state['m'],
            'd' => $state['d'],
            'h' => $state['h'],
            'i' => $state['i'],
            's' => $state['s'],
            'f' => $state['f'],
            'invert' => $state['invert'],
            'days' => $state['days'],
            'from_string' => $fromString,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function restoreFromZendSerialize(Context $ctx, array $data): ?ObjectEntry
    {
        $state = [
            'y' => isset($data['y']) && \is_int($data['y']) ? $data['y'] : 0,
            'm' => isset($data['m']) && \is_int($data['m']) ? $data['m'] : 0,
            'd' => isset($data['d']) && \is_int($data['d']) ? $data['d'] : 0,
            'h' => isset($data['h']) && \is_int($data['h']) ? $data['h'] : 0,
            'i' => isset($data['i']) && \is_int($data['i']) ? $data['i'] : 0,
            's' => isset($data['s']) && \is_int($data['s']) ? $data['s'] : 0,
            'f' => isset($data['f']) && (\is_int($data['f']) || \is_float($data['f'])) ? (float) $data['f'] : 0.0,
            'invert' => isset($data['invert']) && \is_int($data['invert']) ? $data['invert'] : 0,
            'days' => $data['days'] ?? false,
        ];
        $interval = self::createFromState($ctx, $state);
        if ($interval->hasProperty('from_string')) {
            $fs = $interval->getProperty('from_string')->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $fs->type) {
                $fs->bool(isset($data['from_string']) && true === $data['from_string']);
            }
        }

        return $interval;
    }
}
