<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** SoapHeader envelope attrs — php-src soap.c set_soap_header_attributes (#31920). */
final class SoapHeaderXml
{
    public static function envelopeAttributeString(int $soapVersion, string $prefix, ObjectEntry $header): string
    {
        $attrs = '';
        if (self::mustUnderstand($header)) {
            $attrs .= SoapConstants::SOAP_1_2 === $soapVersion
                ? ' '.$prefix.':mustUnderstand="true"'
                : ' '.$prefix.':mustUnderstand="1"';
        }
        if (!$header->hasProperty('actor')) {
            return $attrs;
        }
        $actorVar = $header->getProperty('actor')->resolveIndirect();
        if (Variable::TYPE_NULL === $actorVar->type) {
            return $attrs;
        }
        $attrName = SoapConstants::SOAP_1_2 === $soapVersion ? 'role' : 'actor';
        if (Variable::TYPE_INTEGER === $actorVar->type) {
            $role = self::actorUriFromInt($soapVersion, (int) $actorVar->toInt());
            if (null === $role) {
                return $attrs;
            }
            $attrs .= ' '.$prefix.':'.$attrName.'="'.\htmlspecialchars($role, \ENT_XML1).'"';
        } else {
            $attrs .= ' '.$prefix.':'.$attrName.'="'.\htmlspecialchars($actorVar->toString(), \ENT_XML1).'"';
        }

        return $attrs;
    }

    private static function mustUnderstand(ObjectEntry $header): bool
    {
        if (!$header->hasProperty('mustUnderstand')) {
            return false;
        }
        $mu = $header->getProperty('mustUnderstand')->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $mu->type) {
            return $mu->toBool();
        }
        if (Variable::TYPE_INTEGER === $mu->type) {
            return 0 !== $mu->toInt();
        }

        return false;
    }

    private static function actorUriFromInt(int $soapVersion, int $actor): ?string
    {
        if (SoapConstants::SOAP_1_2 === $soapVersion) {
            return match ($actor) {
                SoapConstants::SOAP_ACTOR_NEXT => SoapConstants::SOAP_1_2_ACTOR_NEXT,
                SoapConstants::SOAP_ACTOR_NONE => SoapConstants::SOAP_1_2_ACTOR_NONE,
                SoapConstants::SOAP_ACTOR_UNLIMATERECEIVER => SoapConstants::SOAP_1_2_ACTOR_UNLIMATERECEIVER,
                default => null,
            };
        }
        if (SoapConstants::SOAP_ACTOR_NEXT === $actor) {
            return SoapConstants::SOAP_1_1_ACTOR_NEXT;
        }

        return null;
    }
}
