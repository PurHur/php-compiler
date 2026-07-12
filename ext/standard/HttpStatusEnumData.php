<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ResponseCode int-backed enum cases (php-src main/http_status_codes.h, issue #7322).
 *
 * @see ext/standard/basic_functions.stub.php — enum ResponseCode: int
 */
final class HttpStatusEnumData
{
    /** @return array<string, int> case name => HTTP status */
    public static function cases(): array
    {
        return [
            'Continue' => 100,
            'SwitchingProtocols' => 101,
            'OK' => 200,
            'Created' => 201,
            'Accepted' => 202,
            'NonAuthoritativeInformation' => 203,
            'NoContent' => 204,
            'ResetContent' => 205,
            'PartialContent' => 206,
            'MultipleChoices' => 300,
            'MovedPermanently' => 301,
            'Found' => 302,
            'SeeOther' => 303,
            'NotModified' => 304,
            'UseProxy' => 305,
            'TemporaryRedirect' => 307,
            'PermanentRedirect' => 308,
            'BadRequest' => 400,
            'Unauthorized' => 401,
            'PaymentRequired' => 402,
            'Forbidden' => 403,
            'NotFound' => 404,
            'MethodNotAllowed' => 405,
            'NotAcceptable' => 406,
            'ProxyAuthenticationRequired' => 407,
            'RequestTimeout' => 408,
            'Conflict' => 409,
            'Gone' => 410,
            'LengthRequired' => 411,
            'PreconditionFailed' => 412,
            'RequestEntityTooLarge' => 413,
            'RequestUriTooLong' => 414,
            'UnsupportedMediaType' => 415,
            'RequestedRangeNotSatisfiable' => 416,
            'ExpectationFailed' => 417,
            'TooEarly' => 425,
            'UpgradeRequired' => 426,
            'PreconditionRequired' => 428,
            'TooManyRequests' => 429,
            'RequestHeaderFieldsTooLarge' => 431,
            'UnavailableForLegalReasons' => 451,
            'InternalServerError' => 500,
            'NotImplemented' => 501,
            'BadGateway' => 502,
            'ServiceUnavailable' => 503,
            'GatewayTimeout' => 504,
            'HttpVersionNotSupported' => 505,
            'VariantAlsoNegotiates' => 506,
            'NetworkAuthenticationRequired' => 511,
        ];
    }

    /** @return array<int, string> */
    public static function caseNameByCode(): array
    {
        static $map = null;
        if (null === $map) {
            $map = [];
            foreach (self::cases() as $name => $code) {
                $map[$code] = $name;
            }
        }

        return $map;
    }
}
