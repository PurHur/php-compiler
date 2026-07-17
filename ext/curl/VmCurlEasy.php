<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;

/**
 * Minimal CurlHandle stubs for CURLOPT_SHARE attachment (php-src ext/curl/interface.c; #6322).
 *
 * HTTP I/O remains #3325; this tracks share association only.
 */
final class VmCurlEasy
{
    public const CLASS_LC = 'curlhandle';

    /** @var array<int, array{closed: bool, url: ?string, share_id: ?int}> */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('CurlHandle');
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function init(?string $url, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = ['closed' => false, 'url' => $url, 'share_id' => null];
        $var->object($object);

        return $var;
    }

    public static function setopt(ObjectEntry $easy, int $option, Variable $value, Frame $frame): bool
    {
        self::ensureLive($easy, 'curl_setopt');
        if (CurlConstants::CURLOPT_SHARE === $option) {
            $share = VmCurlArg::requireShareObject($value, 'curl_setopt', 3);
            VmCurlShare::attachToEasy($share);
            self::$state[$easy->id]['share_id'] = $share->id;

            return true;
        }
        if (CurlConstants::CURLOPT_URL === $option) {
            self::$state[$easy->id]['url'] = VmString::coerceStringBuiltinArg(
                $value,
                'curl_setopt',
                2,
                'value'
            );

            return true;
        }

        return true;
    }

    public static function setoptArray(ObjectEntry $easy, Variable $optionsVar, Frame $frame): bool
    {
        self::ensureLive($easy, 'curl_setopt_array');
        $optionsVar = $optionsVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $optionsVar->type) {
            throw new \TypeError(\sprintf(
                'curl_setopt_array(): Argument #2 ($options) must be of type array, %s given',
                EnumCaseSupport::typeNameForVariable($optionsVar)
            ));
        }

        foreach ($optionsVar->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $keyVar = $keyVar->resolveIndirect();
            $option = self::parseOptionKey($keyVar);
            if (!CurlConstants::isValidEasyOption($option)) {
                throw new \ValueError(
                    Variable::TYPE_STRING === $keyVar->type && !is_numeric($keyVar->toString())
                        ? 'curl_setopt_array(): Argument #2 ($options) contains an invalid cURL option'
                        : 'curl_setopt_array(): Argument #2 ($options) must contain only valid cURL options'
                );
            }
            if (!self::setopt($easy, $option, $valueVar, $frame)) {
                return false;
            }
        }

        return true;
    }

    public static function close(ObjectEntry $easy): void
    {
        if (!isset(self::$state[$easy->id])) {
            return;
        }
        self::$state[$easy->id]['closed'] = true;
        unset(self::$state[$easy->id]);
    }

    public static function isEasyObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    public static function isLiveEasyObject(ObjectEntry $object): bool
    {
        return self::isEasyObject($object) && isset(self::$state[$object->id]) && !self::$state[$object->id]['closed'];
    }

    public static function shareIdForEasy(ObjectEntry $easy): ?int
    {
        return self::$state[$easy->id]['share_id'] ?? null;
    }

    private static function ensureLive(ObjectEntry $easy, string $function): void
    {
        if (!self::isEasyObject($easy)) {
            throw new \TypeError($function.'(): Argument #1 ($handle) must be of type CurlHandle');
        }
        if (!isset(self::$state[$easy->id])) {
            return;
        }
    }

    private static function parseOptionKey(Variable $keyVar): int
    {
        if (Variable::TYPE_INTEGER === $keyVar->type) {
            return $keyVar->toInt();
        }
        if (Variable::TYPE_STRING === $keyVar->type) {
            $s = $keyVar->toString();
            if ('' === $s || !is_numeric($s)) {
                throw new \ValueError('curl_setopt_array(): Argument #2 ($options) contains an invalid cURL option');
            }

            return VmMath::parseIntBuiltinArg($keyVar, 'curl_setopt_array', 2, 'options');
        }

        throw new \ValueError('curl_setopt_array(): Argument #2 ($options) contains an invalid cURL option');
    }
}
