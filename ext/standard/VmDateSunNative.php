<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native date_sunrise()/date_sunset()/date_sun_info() without host Zend (ext/date/lib/astro.c, #8003).
 *
 * Port of timelib Paul Schlyter / Derick Rethans public-domain astro algorithms.
 */
final class VmDateSunNative
{
    private const PI = 3.1415926535897932384;
    private const RADEG = 180.0 / self::PI;
    private const DEGRAD = self::PI / 180.0;

    /** date.sunrise_zenith / date.sunset_zenith ini default (ext/date/php_date.c). */
    private const SUN_RISE_SET_ZENITH = 90.833333;

    /**
     * Rise/set altitude matching date_sunrise()/date_sunset() (90 - zenith).
     *
     * php-src passes -35/60 to timelib_astro_rise_set_altitude for date_sun_info();
     * timelib yields the same timestamps as the procedural helpers — our port must
     * use the zenith-derived altitude to match Zend (issue #15629).
     */
    private const SUN_RISE_SET_ALTITUDE = 90.0 - self::SUN_RISE_SET_ZENITH;

    /**
     * @return string|int|float|false
     */
    public static function sunriseSunset(
        bool $isSunset,
        int $timestamp,
        int $returnFormat,
        ?float $latitude,
        ?float $longitude,
        ?float $zenith,
        ?float $gmtOffset,
        int $argc
    ): mixed {
        if (null === $latitude) {
            $latitude = 31.7667;
        }
        if (null === $longitude) {
            $longitude = 35.2333;
        }
        if (null === $zenith) {
            $zenith = self::SUN_RISE_SET_ZENITH;
        }
        if ($argc < 2) {
            $returnFormat = VmDate::SUNFUNCS_RET_STRING;
        }
        if ($returnFormat !== VmDate::SUNFUNCS_RET_TIMESTAMP
            && $returnFormat !== VmDate::SUNFUNCS_RET_STRING
            && $returnFormat !== VmDate::SUNFUNCS_RET_DOUBLE) {
            throw new \ValueError(
                'date_sun'.($isSunset ? 'set' : 'rise').'(): Argument #2 ($returnFormat) must be one of '
                .'SUNFUNCS_RET_TIMESTAMP, SUNFUNCS_RET_STRING, or SUNFUNCS_RET_DOUBLE'
            );
        }
        if (!\is_finite($latitude) || !\is_finite($longitude)) {
            return false;
        }

        $altitude = 90.0 - $zenith;
        if (null === $gmtOffset) {
            $gmtOffset = self::currentGmtOffsetHours($timestamp);
        }

        $riseSet = self::riseSetAltitude($timestamp, $longitude, $latitude, $altitude, true);
        if (0 !== $riseSet['rc']) {
            return false;
        }

        if (VmDate::SUNFUNCS_RET_TIMESTAMP === $returnFormat) {
            return (int) ($isSunset ? $riseSet['ts_set'] : $riseSet['ts_rise']);
        }

        $hours = ($isSunset ? $riseSet['h_set'] : $riseSet['h_rise']) + $gmtOffset;
        if ($hours > 24.0 || $hours < 0.0) {
            $hours -= \floor($hours / 24.0) * 24.0;
        }
        if (!($hours <= 24.0 && $hours >= 0.0)) {
            return false;
        }

        if (VmDate::SUNFUNCS_RET_STRING === $returnFormat) {
            return \sprintf('%02d:%02d', (int) $hours, (int) (60.0 * ($hours - (int) $hours)));
        }

        return $hours;
    }

    /**
     * @return array<string, int|bool>
     */
    public static function sunInfo(int $timestamp, float $latitude, float $longitude): array
    {
        $out = [];

        $sun = self::riseSetAltitude($timestamp, $longitude, $latitude, self::SUN_RISE_SET_ALTITUDE, true);
        self::assignSunInfoEntry($out, 'sunrise', $sun['rc'], (int) $sun['ts_rise']);
        self::assignSunInfoEntry($out, 'sunset', $sun['rc'], (int) $sun['ts_set']);
        $out['transit'] = (int) $sun['ts_transit'];

        $civil = self::riseSetAltitude($timestamp, $longitude, $latitude, -6.0, false);
        self::assignSunInfoEntry($out, 'civil_twilight_begin', $civil['rc'], (int) $civil['ts_rise']);
        self::assignSunInfoEntry($out, 'civil_twilight_end', $civil['rc'], (int) $civil['ts_set']);

        $nautical = self::riseSetAltitude($timestamp, $longitude, $latitude, -12.0, false);
        self::assignSunInfoEntry($out, 'nautical_twilight_begin', $nautical['rc'], (int) $nautical['ts_rise']);
        self::assignSunInfoEntry($out, 'nautical_twilight_end', $nautical['rc'], (int) $nautical['ts_set']);

        $astro = self::riseSetAltitude($timestamp, $longitude, $latitude, -18.0, false);
        self::assignSunInfoEntry($out, 'astronomical_twilight_begin', $astro['rc'], (int) $astro['ts_rise']);
        self::assignSunInfoEntry($out, 'astronomical_twilight_end', $astro['rc'], (int) $astro['ts_set']);

        return $out;
    }

    /**
     * @param array<string, int|bool> $out
     */
    private static function assignSunInfoEntry(array &$out, string $key, int $rc, int $ts): void
    {
        if (-1 === $rc) {
            $out[$key] = false;

            return;
        }
        if (1 === $rc) {
            $out[$key] = true;

            return;
        }
        $out[$key] = $ts;
    }

    /**
     * @return array{
     *     rc: int,
     *     ts_rise: float,
     *     ts_set: float,
     *     ts_transit: float,
     *     h_rise: float,
     *     h_set: float
     * }
     */
    private static function riseSetAltitude(
        int $timestamp,
        float $longitude,
        float $latitude,
        float $altitude,
        bool $upperLimb
    ): array {
        $year = VmDate::idateValue('Y', $timestamp);
        $month = VmDate::idateValue('n', $timestamp);
        $day = VmDate::idateValue('j', $timestamp);
        if (false === $year || false === $month || false === $day) {
            return self::emptyRiseSet(0);
        }

        $utcMidnight = VmDate::gmmktime(0, 0, 0, $month, $day, $year);
        if (false === $utcMidnight) {
            return self::emptyRiseSet(0);
        }

        $d = self::tsToJ2000($utcMidnight) + 2.0 - $longitude / 360.0;
        $sidtime = self::revolution(self::gmst0($d) + 180.0 + $longitude);
        self::sunRaDec($d, $ra, $dec, $sr);
        $tsouth = 12.0 - self::rev180($sidtime - $ra) / 15.0;
        $sradius = 0.2666 / $sr;
        if ($upperLimb) {
            $altitude -= $sradius;
        }

        $cost = (self::sind($altitude) - self::sind($latitude) * self::sind($dec))
            / (self::cosd($latitude) * self::cosd($dec));
        $tsTransit = $utcMidnight + ($tsouth * 3600.0);

        if ($cost >= 1.0) {
            return [
                'rc' => -1,
                'ts_rise' => $tsTransit,
                'ts_set' => $tsTransit,
                'ts_transit' => $tsTransit,
                'h_rise' => $tsouth,
                'h_set' => $tsouth,
            ];
        }
        if ($cost <= -1.0) {
            $noon = VmDate::gmmktime(12, 0, 0, $month, $day, $year);
            if (false === $noon) {
                $noon = $utcMidnight + 43200;
            }

            return [
                'rc' => 1,
                'ts_rise' => (float) ($noon - 43200),
                'ts_set' => (float) ($noon + 43200),
                'ts_transit' => $tsTransit,
                'h_rise' => $tsouth - 12.0,
                'h_set' => $tsouth + 12.0,
            ];
        }

        $t = self::acosd($cost) / 15.0;
        $hRise = $tsouth - $t;
        $hSet = $tsouth + $t;

        return [
            'rc' => 0,
            'ts_rise' => ($hRise * 3600.0) + $utcMidnight,
            'ts_set' => ($hSet * 3600.0) + $utcMidnight,
            'ts_transit' => $tsTransit,
            'h_rise' => $hRise,
            'h_set' => $hSet,
        ];
    }

    /**
     * @return array{rc: int, ts_rise: float, ts_set: float, ts_transit: float, h_rise: float, h_set: float}
     */
    private static function emptyRiseSet(int $rc): array
    {
        return [
            'rc' => $rc,
            'ts_rise' => 0.0,
            'ts_set' => 0.0,
            'ts_transit' => 0.0,
            'h_rise' => 0.0,
            'h_set' => 0.0,
        ];
    }

    private static function currentGmtOffsetHours(int $timestamp): float
    {
        if ('UTC' === VmDate::defaultTimezoneGet()) {
            return 0.0;
        }

        return VmDateTimeNative::timezoneOffsetSeconds(
            VmDate::defaultTimezoneGet(),
            $timestamp
        ) / 3600.0;
    }

    private static function tsToJ2000(int $ts): float
    {
        return ($ts / 86400.0) + 2440587.5 - 2451545.0;
    }

    private static function revolution(float $x): float
    {
        return $x - 360.0 * \floor($x / 360.0);
    }

    private static function rev180(float $x): float
    {
        return $x - 360.0 * \floor($x / 360.0 + 0.5);
    }

    private static function gmst0(float $d): float
    {
        return self::revolution((180.0 + 356.0470 + 282.9404) + (0.9856002585 + 4.70935E-5) * $d);
    }

    private static function sunRaDec(float $d, ?float &$ra, ?float &$dec, ?float &$r): void
    {
        self::sunpos($d, $lon, $r);
        $x = $r * self::cosd($lon);
        $y = $r * self::sind($lon);
        $oblEcl = 23.4393 - 3.563E-7 * $d;
        $z = $y * self::sind($oblEcl);
        $y = $y * self::cosd($oblEcl);
        $ra = self::atan2d($y, $x);
        $dec = self::atan2d($z, \sqrt($x * $x + $y * $y));
    }

    private static function sunpos(float $d, ?float &$lon, ?float &$r): void
    {
        $m = self::revolution(356.0470 + 0.9856002585 * $d);
        $w = 282.9404 + 4.70935E-5 * $d;
        $e = 0.016709 - 1.151E-9 * $d;
        $ecc = $m + $e * self::RADEG * self::sind($m) * (1.0 + $e * self::cosd($m));
        $x = self::cosd($ecc) - $e;
        $y = \sqrt(1.0 - $e * $e) * self::sind($ecc);
        $r = \sqrt($x * $x + $y * $y);
        $v = self::atan2d($y, $x);
        $lon = $v + $w;
        if ($lon >= 360.0) {
            $lon -= 360.0;
        }
    }

    private static function sind(float $x): float
    {
        return \sin($x * self::DEGRAD);
    }

    private static function cosd(float $x): float
    {
        return \cos($x * self::DEGRAD);
    }

    private static function tand(float $x): float
    {
        return \tan($x * self::DEGRAD);
    }

    private static function asind(float $x): float
    {
        return self::RADEG * \asin($x);
    }

    private static function acosd(float $x): float
    {
        return self::RADEG * \acos($x);
    }

    private static function atan2d(float $y, float $x): float
    {
        return self::RADEG * \atan2($y, $x);
    }
}
