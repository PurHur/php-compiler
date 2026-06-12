<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * IPTC embed/parse helpers (php-src ext/standard/iptc.c; issue #6104).
 */
final class VmIptc
{
    private const M_EOI = 0xD9;
    private const M_SOS = 0xDA;
    private const M_APP0 = 0xE0;
    private const M_APP1 = 0xE1;
    private const M_APP13 = 0xED;

    /** Photoshop APP13 prefix (28 bytes); length bytes at index 2–3 are patched per payload. */
    private const PS_HEADER = "\xFF\xED\x00\x00Photoshop 3.0\x008BIM\x04\x04\x00\x00\x00\x00";

    /**
     * iptcparse() — binary IPTC blob to associative array (php-src PHP_FUNCTION(iptcparse)).
     *
     * @return array<string, list<string>>|false
     */
    public static function parse(string $data): array|false
    {
        $len = \strlen($data);
        if (0 === $len) {
            return false;
        }

        $inx = 0;
        while ($inx < $len) {
            if ("\x1c" === $data[$inx]
                && isset($data[$inx + 1])
                && ("\x01" === $data[$inx + 1] || "\x02" === $data[$inx + 1])) {
                break;
            }
            ++$inx;
        }

        $tagsFound = 0;
        $result = [];

        while ($inx < $len) {
            if ("\x1c" !== $data[$inx++]) {
                break;
            }
            if ($inx + 4 >= $len) {
                break;
            }

            $dataset = \ord($data[$inx++]);
            $recnum = \ord($data[$inx++]);
            $sizeByte = \ord($data[$inx]);

            if (0 !== ($sizeByte & 0x80)) {
                if ($inx + 6 >= $len) {
                    break;
                }
                $recLen = ((\ord($data[$inx + 2]) << 24)
                    + (\ord($data[$inx + 3]) << 16)
                    + (\ord($data[$inx + 4]) << 8)
                    + \ord($data[$inx + 5]));
                $inx += 6;
            } else {
                $recLen = ((\ord($data[$inx]) << 8) | \ord($data[$inx + 1]));
                $inx += 2;
            }

            if ($recLen > $len || $inx + $recLen > $len) {
                break;
            }

            $key = \sprintf('%d#%03d', $dataset, $recnum);
            if (!isset($result[$key])) {
                $result[$key] = [];
            }
            $result[$key][] = \substr($data, $inx, $recLen);
            $inx += $recLen;
            ++$tagsFound;
        }

        return 0 === $tagsFound ? false : $result;
    }

    /**
     * iptcembed() — embed IPTC payload into a JPEG file (php-src PHP_FUNCTION(iptcembed)).
     *
     * @return string|true|false spool &lt; 2 returns JPEG bytes; spool &gt;= 2 returns true on success
     */
    public static function embed(string $iptcData, string $jpegPath, int $spool = 0): string|bool
    {
        $maxHeader = 28 + 1024;
        if (\strlen($iptcData) >= \PHP_INT_MAX - \strlen(self::PS_HEADER) - $maxHeader) {
            throw new \ValueError('iptcembed(): Argument #1 ($iptcdata) is too large');
        }

        $jpeg = @\file_get_contents($jpegPath);
        if (false === $jpeg) {
            return false;
        }

        $embedded = self::embedBuffer($jpeg, $iptcData);
        if (false === $embedded) {
            return false;
        }

        if ($spool > 0) {
            echo $embedded;
        }

        if ($spool < 2) {
            return $embedded;
        }

        return true;
    }

    /**
     * @return string|false
     */
    private static function embedBuffer(string $jpeg, string $iptcData): string|false
    {
        $len = \strlen($jpeg);
        if ($len < 2 || "\xFF" !== $jpeg[0] || "\xD8" !== $jpeg[1]) {
            return false;
        }

        $iptcLen = \strlen($iptcData);
        if ($iptcLen & 1) {
            ++$iptcLen;
        }

        $out = "\xFF\xD8";
        $pos = 2;
        $written = false;
        $done = false;

        while (!$done && $pos < $len) {
            $marker = self::nextMarkerInBuffer($jpeg, $len, $pos);
            if (self::M_EOI === $marker) {
                $out .= "\xFF".\chr(self::M_EOI);
                break;
            }

            if (self::M_APP13 !== $marker) {
                $out .= \chr($marker);
            }

            switch ($marker) {
                case self::M_APP13:
                    self::skipVariableInBuffer($jpeg, $len, $pos);
                    if ($pos < $len && "\xFF" === $jpeg[$pos]) {
                        ++$pos;
                    }
                    $out .= \substr($jpeg, $pos);
                    $pos = $len;
                    $done = true;
                    break;

                case self::M_APP0:
                case self::M_APP1:
                    if ($written) {
                        $out .= self::readVariableInBuffer($jpeg, $len, $pos);
                        break;
                    }
                    $written = true;
                    self::skipVariableInBuffer($jpeg, $len, $pos);
                    $segment = self::buildApp13Segment($iptcData, $iptcLen);
                    if (false === $segment) {
                        return false;
                    }
                    $out .= $segment;
                    break;

                case self::M_SOS:
                    $out .= self::readVariableInBuffer($jpeg, $len, $pos);
                    if ($pos < $len) {
                        $out .= \substr($jpeg, $pos);
                    }
                    $pos = $len;
                    $done = true;
                    break;

                default:
                    $out .= self::readVariableInBuffer($jpeg, $len, $pos);
                    break;
            }
        }

        return $out;
    }

    private static function buildApp13Segment(string $iptcData, int $iptcLen): string|false
    {
        $header = self::PS_HEADER;
        $segLen = $iptcLen + 28;
        $header[2] = \chr(($segLen >> 8) & 0xFF);
        $header[3] = \chr($segLen & 0xFF);

        $segment = $header;
        $segment .= \chr(($iptcLen >> 8) & 0xFF).\chr($iptcLen & 0xFF);
        $segment .= $iptcData;
        if ($iptcLen > \strlen($iptcData)) {
            $segment .= "\x00";
        }

        return $segment;
    }

    private static function nextMarkerInBuffer(string $jpeg, int $len, int &$pos): int
    {
        if ($pos >= $len) {
            return self::M_EOI;
        }

        while ($pos < $len && "\xFF" !== $jpeg[$pos]) {
            ++$pos;
        }
        if ($pos >= $len) {
            return self::M_EOI;
        }

        ++$pos;
        while ($pos < $len && "\xFF" === $jpeg[$pos]) {
            ++$pos;
        }
        if ($pos >= $len) {
            return self::M_EOI;
        }

        return \ord($jpeg[$pos++]);
    }

    private static function skipVariableInBuffer(string $jpeg, int $len, int &$pos): void
    {
        if ($pos + 2 > $len) {
            $pos = $len;

            return;
        }

        $segLen = ((\ord($jpeg[$pos]) << 8) | \ord($jpeg[$pos + 1]));
        $pos += $segLen;
        if ($pos > $len) {
            $pos = $len;
        }
    }

    private static function readVariableInBuffer(string $jpeg, int $len, int &$pos): string
    {
        if ($pos + 2 > $len) {
            $tail = \substr($jpeg, $pos);
            $pos = $len;

            return $tail;
        }

        $segLen = ((\ord($jpeg[$pos]) << 8) | \ord($jpeg[$pos + 1]));
        $end = $pos + $segLen;
        if ($end > $len) {
            $end = $len;
        }
        $chunk = \substr($jpeg, $pos, $end - $pos);
        $pos = $end;

        return $chunk;
    }

    /** @return array<string, list<string>> */
    public static function parseToHashTable(string $data): array
    {
        $parsed = self::parse($data);

        return \is_array($parsed) ? $parsed : [];
    }
}
