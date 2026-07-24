<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * XML-RPC encode/decode + request/type helpers (php-src ext/xmlrpc/xmlrpc-epi-php.c; #6579, #22254).
 *
 * PHP-in-PHP value serialization — string XML parser (no host DOMDocument; #19048 AOT).
 */
final class VmXmlrpc
{
    /** Struct key used by {@see setType()} / {@see getType()} / encode for base64|datetime. */
    public const TYPED_VALUE_KEY = '__xmlrpc_type__';

    private static ?string $lastError = null;

    public static function getLastError(): string
    {
        return self::$lastError ?? '';
    }

    public static function clearLastError(): void
    {
        self::$lastError = null;
    }

    public static function encode(Variable $value): string
    {
        self::$lastError = null;
        $inner = self::encodeValue($value->resolveIndirect());

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<param>'."\n"
            .'<value>'.$inner.'</value>'."\n"
            .'</param>';
    }

    /**
     * @return mixed|false
     */
    public static function decode(string $xml)
    {
        self::$lastError = null;
        $xml = trim($xml);
        if ('' === $xml || !str_contains($xml, '<')) {
            self::$lastError = 'Invalid XML';

            return false;
        }

        $valueInner = self::extractBalancedElementInner($xml, 'value');
        if (null === $valueInner) {
            self::$lastError = 'Invalid XML';

            return false;
        }

        try {
            return self::decodeValueString($valueInner);
        } catch (\Throwable) {
            self::$lastError = 'Invalid XML-RPC payload';

            return false;
        }
    }

    /**
     * xmlrpc_encode_request() — methodCall / fault response XML (#22254).
     *
     * php-src: ext/xmlrpc/xmlrpc-epi-php.c — PHP_FUNCTION(xmlrpc_encode_request)
     */
    public static function encodeRequest(?string $method, Variable $params): string
    {
        self::$lastError = null;
        $resolved = $params->resolveIndirect();
        if (null === $method || '' === $method) {
            if (Variable::TYPE_ARRAY === $resolved->type && self::isFaultArray($resolved->toArray())) {
                return self::encodeFaultResponse($resolved);
            }

            return self::encodeMethodResponse($resolved);
        }

        $paramValues = self::requestParamList($resolved);
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<methodCall>'."\n"
            .'<methodName>'.self::escapeXml($method).'</methodName>'."\n"
            .'<params>'."\n";
        foreach ($paramValues as $param) {
            $out .= '<param><value>'.self::encodeValue($param).'</value></param>'."\n";
        }

        return $out.'</params>'."\n".'</methodCall>';
    }

    /**
     * xmlrpc_decode_request() — methodCall/methodResponse → PHP value (#22254).
     *
     * @param-out string $method
     *
     * @return mixed|false
     */
    public static function decodeRequest(string $xml, ?string &$method)
    {
        self::$lastError = null;
        $method = '';
        $xml = trim($xml);
        if ('' === $xml || !str_contains($xml, '<')) {
            self::$lastError = 'Invalid XML';

            return false;
        }

        $callInner = self::extractBalancedElementInner($xml, 'methodCall');
        if (null !== $callInner) {
            $nameInner = self::extractBalancedElementInner($callInner, 'methodName');
            $method = null !== $nameInner ? trim($nameInner) : '';
            $paramsInner = self::extractBalancedElementInner($callInner, 'params');
            if (null === $paramsInner) {
                return [];
            }

            return self::decodeParamsList($paramsInner);
        }

        $responseInner = self::extractBalancedElementInner($xml, 'methodResponse');
        if (null !== $responseInner) {
            $faultInner = self::extractBalancedElementInner($responseInner, 'fault');
            if (null !== $faultInner) {
                $valueInner = self::extractBalancedElementInner($faultInner, 'value');
                if (null === $valueInner) {
                    self::$lastError = 'Invalid XML-RPC fault';

                    return false;
                }

                return self::decodeValueString($valueInner);
            }
            $paramsInner = self::extractBalancedElementInner($responseInner, 'params');
            if (null !== $paramsInner) {
                $list = self::decodeParamsList($paramsInner);

                return 1 === \count($list) ? $list[0] : $list;
            }
            $valueInner = self::extractBalancedElementInner($responseInner, 'value');
            if (null !== $valueInner) {
                return self::decodeValueString($valueInner);
            }
        }

        return self::decode($xml);
    }

    /**
     * xmlrpc_is_fault() — faultCode + faultString array (#22254).
     */
    public static function isFault(Variable $arg): bool
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            return false;
        }

        return self::isFaultArray($arg->toArray());
    }

    /**
     * xmlrpc_get_type() — XML-RPC type name for a PHP value (#22254).
     */
    public static function getType(Variable $value): string
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $value->type) {
            $object = $value->toObject();
            if ($object->hasProperty('xmlrpc_type')) {
                $typeProp = $object->getProperty('xmlrpc_type')->resolveIndirect();
                if (Variable::TYPE_STRING === $typeProp->type) {
                    $typed = strtolower($typeProp->toString(null));
                    if ('base64' === $typed || 'datetime' === $typed) {
                        return $typed;
                    }
                }
            }

            return 'struct';
        }
        if (Variable::TYPE_ARRAY === $value->type) {
            $typed = self::typedArrayKind($value->toArray());
            if (null !== $typed) {
                return $typed;
            }

            return $value->toArray()->isPackedList() ? 'array' : 'struct';
        }

        return match ($value->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'boolean',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'double',
            Variable::TYPE_STRING => 'string',
            default => 'unknown',
        };
    }

    /**
     * xmlrpc_set_type() — mark string as base64/datetime for encode (#22254).
     *
     * Stored as a struct array with {@see TYPED_VALUE_KEY} so by-ref call sites keep
     * the payload (stdClass dynamic props were cleared across builtin return).
     */
    public static function setType(Variable $valueSlot, string $type): bool
    {
        $type = strtolower(trim($type));
        if ('datetime.iso8601' === $type) {
            $type = 'datetime';
        }
        if ('base64' !== $type && 'datetime' !== $type) {
            return false;
        }

        $resolved = $valueSlot->resolveIndirect();
        $scalar = match ($resolved->type) {
            Variable::TYPE_STRING => $resolved->toString(null),
            Variable::TYPE_INTEGER => (string) $resolved->toInt(null),
            Variable::TYPE_FLOAT => self::formatDouble($resolved->toFloat(null)),
            Variable::TYPE_BOOLEAN => $resolved->toBool(null) ? '1' : '0',
            Variable::TYPE_NULL => '',
            default => (string) $resolved->toString(null),
        };

        $ht = new HashTable();
        $typeVar = new Variable();
        $typeVar->string($type);
        $ht->add(self::TYPED_VALUE_KEY, $typeVar);
        $scalarVar = new Variable();
        $scalarVar->string($scalar);
        $ht->add('scalar', $scalarVar);
        if ('datetime' === $type) {
            $ts = strtotime($scalar);
            $tsVar = new Variable();
            $tsVar->int(false === $ts ? 0 : $ts);
            $ht->add('timestamp', $tsVar);
        }
        $resolved->array($ht);

        return true;
    }

    private static function encodeValue(Variable $value): string
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $value->type) {
            $object = $value->toObject();
            if ($object->hasProperty('xmlrpc_type')) {
                $typeProp = $object->getProperty('xmlrpc_type')->resolveIndirect();
                if (Variable::TYPE_STRING === $typeProp->type) {
                    $typed = strtolower($typeProp->toString(null));
                    $scalar = '';
                    if ($object->hasProperty('scalar')) {
                        $scalarProp = $object->getProperty('scalar')->resolveIndirect();
                        if (Variable::TYPE_STRING === $scalarProp->type) {
                            $scalar = $scalarProp->toString(null);
                        }
                    }
                    if ('base64' === $typed) {
                        return '<base64>'.base64_encode($scalar).'</base64>';
                    }
                    if ('datetime' === $typed) {
                        return '<dateTime.iso8601>'.self::escapeXml($scalar).'</dateTime.iso8601>';
                    }
                }
            }

            throw new \Exception('Cannot xmlrpc_encode() value of type object');
        }
        if (Variable::TYPE_ARRAY === $value->type) {
            $table = $value->toArray();
            $typed = self::typedArrayKind($table);
            if (null !== $typed) {
                $scalar = self::typedArrayScalar($table);
                if ('base64' === $typed) {
                    return '<base64>'.base64_encode($scalar).'</base64>';
                }

                return '<dateTime.iso8601>'.self::escapeXml($scalar).'</dateTime.iso8601>';
            }

            return self::encodeArray($table);
        }

        return match ($value->type) {
            Variable::TYPE_NULL => '<string></string>',
            Variable::TYPE_BOOLEAN => '<boolean>'.($value->toBool(null) ? '1' : '0').'</boolean>',
            Variable::TYPE_INTEGER => '<int>'.$value->toInt(null).'</int>',
            Variable::TYPE_FLOAT => '<double>'.self::formatDouble($value->toFloat(null)).'</double>',
            Variable::TYPE_STRING => '<string>'.self::escapeXml($value->toString(null)).'</string>',
            default => throw new \Exception('Cannot xmlrpc_encode() value of type '.$value->type),
        };
    }

    private static function typedArrayKind(HashTable $table): ?string
    {
        foreach (iterator_to_array($table->iterateKeyed(true), false) as [$key, $element]) {
            if (self::TYPED_VALUE_KEY !== self::arrayKeyToString($key->resolveIndirect())) {
                continue;
            }
            $element = $element->resolveIndirect();
            if (Variable::TYPE_STRING !== $element->type) {
                return null;
            }
            $typed = strtolower($element->toString(null));

            return ('base64' === $typed || 'datetime' === $typed) ? $typed : null;
        }

        return null;
    }

    private static function typedArrayScalar(HashTable $table): string
    {
        foreach (iterator_to_array($table->iterateKeyed(true), false) as [$key, $element]) {
            if ('scalar' !== self::arrayKeyToString($key->resolveIndirect())) {
                continue;
            }
            $element = $element->resolveIndirect();

            return Variable::TYPE_STRING === $element->type ? $element->toString(null) : '';
        }

        return '';
    }

    private static function encodeArray(HashTable $table): string
    {
        $pairs = iterator_to_array($table->iterateKeyed(true), false);
        if ($table->isPackedList()) {
            $out = '<array><data>';
            foreach ($pairs as [, $element]) {
                $out .= '<value>'.self::encodeValue($element).'</value>';
            }

            return $out.'</data></array>';
        }

        $out = '<struct>';
        foreach ($pairs as [$key, $element]) {
            $name = self::arrayKeyToString($key->resolveIndirect());
            $out .= '<member><name>'.self::escapeXml($name).'</name><value>'
                .self::encodeValue($element).'</value></member>';
        }

        return $out.'</struct>';
    }

    /**
     * @return list<Variable>
     */
    private static function requestParamList(Variable $params): array
    {
        if (Variable::TYPE_ARRAY !== $params->type) {
            return [$params];
        }
        $table = $params->toArray();
        if (!$table->isPackedList()) {
            return [$params];
        }
        $out = [];
        foreach (iterator_to_array($table->iterateKeyed(true), false) as [, $element]) {
            $out[] = $element;
        }

        return $out;
    }

    private static function encodeMethodResponse(Variable $value): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<methodResponse>'."\n"
            .'<params>'."\n"
            .'<param><value>'.self::encodeValue($value).'</value></param>'."\n"
            .'</params>'."\n"
            .'</methodResponse>';
    }

    private static function encodeFaultResponse(Variable $fault): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<methodResponse>'."\n"
            .'<fault>'."\n"
            .'<value>'.self::encodeValue($fault).'</value>'."\n"
            .'</fault>'."\n"
            .'</methodResponse>';
    }

    private static function isFaultArray(HashTable $table): bool
    {
        $hasCode = false;
        $hasString = false;
        foreach (iterator_to_array($table->iterateKeyed(true), false) as [$key, $element]) {
            $name = strtolower(self::arrayKeyToString($key->resolveIndirect()));
            if ('faultcode' === $name) {
                $hasCode = true;
            } elseif ('faultstring' === $name) {
                $hasString = true;
            }
        }

        return $hasCode && $hasString;
    }

    /**
     * @return list<mixed>
     */
    private static function decodeParamsList(string $paramsInner): array
    {
        $out = [];
        $pos = 0;
        $len = \strlen($paramsInner);
        while ($pos < $len) {
            if (!preg_match('/<param(\s[^>]*)?>/i', $paramsInner, $match, PREG_OFFSET_CAPTURE, $pos)) {
                break;
            }
            $sliceStart = $match[0][1];
            $paramInner = self::extractBalancedElementInner(\substr($paramsInner, $sliceStart), 'param');
            if (null === $paramInner) {
                break;
            }
            $valueInner = self::extractBalancedElementInner($paramInner, 'value');
            if (null !== $valueInner) {
                $out[] = self::decodeValueString($valueInner);
            }
            $closePos = stripos($paramsInner, '</param>', $sliceStart);
            if (false === $closePos) {
                break;
            }
            $pos = $closePos + 8;
        }

        return $out;
    }

    private static function arrayKeyToString(Variable $key): string
    {
        return match ($key->type) {
            Variable::TYPE_STRING => $key->toString(null),
            Variable::TYPE_INTEGER => (string) $key->toInt(null),
            default => (string) $key->toString(null),
        };
    }

    private static function escapeXml(string $value): string
    {
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            $value
        );
    }

    private static function formatDouble(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }
        if ($value === INF) {
            return 'INF';
        }
        if ($value === -INF) {
            return '-INF';
        }

        return rtrim(rtrim(sprintf('%.17F', $value), '0'), '.');
    }

    private static function extractBalancedElementInner(string $xml, string $tag): ?string
    {
        $tagPattern = preg_quote($tag, '/');
        if (!preg_match('/<'.$tagPattern.'(\s[^>]*)?>/i', $xml, $open, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $innerStart = $open[0][1] + \strlen($open[0][0]);
        $depth = 1;
        $pos = $innerStart;
        $closePattern = '/<(\/?)'.$tagPattern.'(\s[^>]*)?>/i';
        while ($depth > 0 && preg_match($closePattern, $xml, $match, PREG_OFFSET_CAPTURE, $pos)) {
            $isClose = '' !== $match[1][0];
            $pos = $match[0][1] + \strlen($match[0][0]);
            if ($isClose) {
                --$depth;
                if (0 === $depth) {
                    return \substr($xml, $innerStart, $match[0][1] - $innerStart);
                }
            } else {
                ++$depth;
            }
        }

        return null;
    }

    /**
     * @return mixed
     */
    private static function decodeValueString(string $valueInner)
    {
        $valueInner = trim($valueInner);
        if ('' === $valueInner) {
            return '';
        }
        if ('<' !== $valueInner[0]) {
            return $valueInner;
        }
        if (!preg_match('/^<([a-zA-Z0-9.]+)/', $valueInner, $tagMatch)) {
            return $valueInner;
        }
        $tag = strtolower($tagMatch[1]);
        $typedInner = self::extractBalancedElementInner($valueInner, $tagMatch[1]);
        if (null === $typedInner) {
            return trim($valueInner);
        }
        $typedInner = trim($typedInner);

        return match ($tag) {
            'int', 'i4', 'i8' => (int) $typedInner,
            'boolean', 'bool' => '1' === $typedInner || 'true' === strtolower($typedInner),
            'double', 'float' => self::parseDouble($typedInner),
            'string' => $typedInner,
            'base64' => self::decodeBase64($typedInner),
            'datetime.iso8601' => $typedInner,
            'array' => self::decodeArrayString($typedInner),
            'struct' => self::decodeStructString($typedInner),
            default => $typedInner,
        };
    }

    /**
     * @return list<mixed>
     */
    private static function decodeArrayString(string $arrayInner): array
    {
        $dataInner = self::extractBalancedElementInner($arrayInner, 'data');
        if (null === $dataInner) {
            return [];
        }
        $out = [];
        $pos = 0;
        $dataLen = \strlen($dataInner);
        while ($pos < $dataLen) {
            if (!preg_match('/<value(\s[^>]*)?>/i', $dataInner, $match, PREG_OFFSET_CAPTURE, $pos)) {
                break;
            }
            $sliceStart = $match[0][1];
            $valueInner = self::extractBalancedElementInner(\substr($dataInner, $sliceStart), 'value');
            if (null === $valueInner) {
                break;
            }
            $out[] = self::decodeValueString($valueInner);
            $closePos = stripos($dataInner, '</value>', $sliceStart);
            if (false === $closePos) {
                break;
            }
            $pos = $closePos + 8;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeStructString(string $structInner): array
    {
        $out = [];
        $pos = 0;
        $structLen = \strlen($structInner);
        while ($pos < $structLen) {
            if (!preg_match('/<member(\s[^>]*)?>/i', $structInner, $memberOpen, PREG_OFFSET_CAPTURE, $pos)) {
                break;
            }
            $sliceStart = $memberOpen[0][1];
            $memberInner = self::extractBalancedElementInner(\substr($structInner, $sliceStart), 'member');
            if (null === $memberInner) {
                break;
            }
            $nameInner = self::extractBalancedElementInner($memberInner, 'name');
            $valueInner = self::extractBalancedElementInner($memberInner, 'value');
            if (null !== $nameInner && null !== $valueInner) {
                $out[trim($nameInner)] = self::decodeValueString($valueInner);
            }
            $closePos = stripos($structInner, '</member>', $sliceStart);
            if (false === $closePos) {
                break;
            }
            $pos = $closePos + 9;
        }

        return $out;
    }

    private static function decodeBase64(string $payload): string
    {
        $decoded = base64_decode(trim($payload), true);

        return false === $decoded ? '' : $decoded;
    }

    private static function parseDouble(string $raw): float
    {
        if ('NAN' === strtoupper($raw)) {
            return NAN;
        }
        if ('INF' === strtoupper($raw)) {
            return INF;
        }
        if ('-INF' === strtoupper($raw)) {
            return -INF;
        }

        return (float) $raw;
    }
}
