<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

/**
 * ZipArchive NestedJIT helper (#35424 / #35437 / #35440 / #35449 / #35450 / #35455) —
 * CREATE/add/close/get/locate/index/rename/delete/status path.
 *
 * Single concurrent archive slot (scalars, not array tables): NestedJIT aborts on
 * nested-array state / refs. Sequential open→close→reopen matches php-src repros.
 *
 * Result encoding: 4-byte LE int32 + optional payload (get/close). Int returns via
 * NestedJIT arrive as ptrtoint of __value__ boxes, so the ABI is string-packed.
 *
 * php-src: ext/zip/php_zip.c — zim_ZipArchive_* (open / addFromString / addFile / close /
 * getFromName / locateName / getFromIndex / getNameIndex / getStatusString /
 * renameName / deleteName / deleteIndex)
 */
final class ZipArchiveJitHelper
{
    private static int $nextId = 1;

    private static int $h1open = 0;

    private static string $h1name = '';

    private static string $h1data = '';

    private static int $h1status = 0;

    public static function exec(string $op, int $a, int $b, string $s1, string $s2): string
    {
        if ('alloc' === $op) {
            $id = self::$nextId;
            self::$nextId = $id + 1;

            return self::pack($id);
        }
        if ('open_create' === $op) {
            $h = $a;
            if ($h <= 0) {
                $h = self::$nextId;
                self::$nextId = $h + 1;
            }
            self::$h1open = 1;
            self::$h1name = '';
            self::$h1data = '';
            self::$h1status = 0;

            return self::pack($h);
        }
        if ('open_read' === $op) {
            $h = $a;
            if ($h <= 0) {
                $h = self::$nextId;
                self::$nextId = $h + 1;
            }
            // Stored zip: find local file header + data (single entry, no CRC check).
            $data = $s1;
            $len = strlen($data);
            self::$h1name = '';
            self::$h1data = '';
            self::$h1open = 0;
            if ($len >= 30 && 0x04034b50 === (ord($data[0]) | (ord($data[1]) << 8) | (ord($data[2]) << 16) | (ord($data[3]) << 24))) {
                $nlen = ord($data[26]) | (ord($data[27]) << 8);
                $xlen = ord($data[28]) | (ord($data[29]) << 8);
                $sz = ord($data[22]) | (ord($data[23]) << 8) | (ord($data[24]) << 16) | (ord($data[25]) << 24);
                $off = 30 + $nlen + $xlen;
                if ($off + $sz <= $len) {
                    self::$h1name = substr($data, 30, $nlen);
                    self::$h1data = substr($data, $off, $sz);
                    self::$h1open = 1;
                    self::$h1status = 0;

                    return self::pack($h);
                }
            }
            self::$h1status = 19;

            return self::pack(-19);
        }
        if ('add' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            self::$h1name = $s1;
            self::$h1data = $s2;
            self::$h1status = 0;

            return self::pack(1);
        }
        // basename for addFile default entryname (#35449) — strrpos/substr only.
        if ('basename' === $op) {
            $p = $s1;
            $slash = strrpos($p, '/');
            if (false === $slash) {
                return self::packPayload(1, $p);
            }

            return self::packPayload(1, substr($p, $slash + 1));
        }
        if ('fail_noent' === $op) {
            self::$h1status = 9;

            return self::pack(0);
        }
        if ('get' === $op) {
            if (1 !== self::$h1open) {
                return self::pack(0);
            }
            if ($s1 !== self::$h1name) {
                self::$h1status = 9;

                return self::pack(0);
            }

            return self::packPayload(1, self::$h1data);
        }
        // locateName — single-entry index 0 or -1 miss (#35437).
        if ('locate' === $op) {
            if (1 !== self::$h1open || '' === self::$h1name || $s1 !== self::$h1name) {
                self::$h1status = 9;

                return self::pack(-1);
            }
            self::$h1status = 0;

            return self::pack(0);
        }
        // getFromIndex — only index 0 in the single-entry slot (#35437).
        if ('get_index' === $op) {
            if (1 !== self::$h1open || '' === self::$h1name || 0 !== $a) {
                self::$h1status = 9;

                return self::pack(0);
            }
            self::$h1status = 0;

            return self::packPayload(1, self::$h1data);
        }
        // getNameIndex — only index 0 in the single-entry slot (#35440 leftover of #35437).
        if ('name_index' === $op) {
            if (1 !== self::$h1open || '' === self::$h1name || 0 !== $a) {
                self::$h1status = 9;

                return self::pack(0);
            }
            self::$h1status = 0;

            return self::packPayload(1, self::$h1name);
        }
        // renameName — single-entry name swap (#35450 leftover of #35424).
        if ('rename' === $op) {
            if ('' === $s2) {
                // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
                throw new \ValueError(
                    'ZipArchive::renameName(): Argument #2 ($new_name) must not be empty'
                );
            }
            if (1 !== self::$h1open || '' === self::$h1name || $s1 !== self::$h1name) {
                self::$h1status = 9;

                return self::pack(0);
            }
            self::$h1name = $s2;
            self::$h1status = 0;

            return self::pack(1);
        }
        // deleteName — clear the single-entry slot (#35450 leftover of #35424).
        if ('delete' === $op) {
            if (1 !== self::$h1open || '' === self::$h1name || '' === $s1 || $s1 !== self::$h1name) {
                self::$h1status = 9;

                return self::pack(0);
            }
            self::$h1name = '';
            self::$h1data = '';
            self::$h1status = 0;

            return self::pack(1);
        }
        // deleteIndex — only index 0 in the single-entry slot (#35455 leftover of #35450).
        if ('delete_index' === $op) {
            if (1 !== self::$h1open || '' === self::$h1name || 0 !== $a) {
                self::$h1status = 9;

                return self::pack(0);
            }
            self::$h1name = '';
            self::$h1data = '';
            self::$h1status = 0;

            return self::pack(1);
        }
        if ('close' === $op) {
            if (1 !== self::$h1open) {
                return self::pack(0);
            }
            $name = self::$h1name;
            $content = self::$h1data;
            $size = strlen($content);
            $nl = strlen($name);
            $local = chr(0x50).chr(0x4b).chr(0x03).chr(0x04)
                .chr(20).chr(0).chr(0).chr(0).chr(0).chr(0)
                .chr(0).chr(0).chr(0).chr(0)
                .chr(0).chr(0).chr(0).chr(0)
                .chr($size & 255).chr(($size >> 8) & 255).chr(($size >> 16) & 255).chr(($size >> 24) & 255)
                .chr($size & 255).chr(($size >> 8) & 255).chr(($size >> 16) & 255).chr(($size >> 24) & 255)
                .chr($nl & 255).chr(($nl >> 8) & 255).chr(0).chr(0)
                .$name.$content;
            $loff = 0;
            $central = chr(0x50).chr(0x4b).chr(0x01).chr(0x02)
                .chr(20).chr(0).chr(20).chr(0).chr(0).chr(0).chr(0).chr(0)
                .chr(0).chr(0).chr(0).chr(0)
                .chr(0).chr(0).chr(0).chr(0)
                .chr($size & 255).chr(($size >> 8) & 255).chr(($size >> 16) & 255).chr(($size >> 24) & 255)
                .chr($size & 255).chr(($size >> 8) & 255).chr(($size >> 16) & 255).chr(($size >> 24) & 255)
                .chr($nl & 255).chr(($nl >> 8) & 255).chr(0).chr(0).chr(0).chr(0)
                .chr(0).chr(0).chr(0).chr(0).chr(0).chr(0).chr(0).chr(0)
                .chr($loff & 255).chr(($loff >> 8) & 255).chr(($loff >> 16) & 255).chr(($loff >> 24) & 255)
                .$name;
            $clen = strlen($central);
            $llen = strlen($local);
            $eocd = chr(0x50).chr(0x4b).chr(0x05).chr(0x06)
                .chr(0).chr(0).chr(0).chr(0)
                .chr(1).chr(0).chr(1).chr(0)
                .chr($clen & 255).chr(($clen >> 8) & 255).chr(($clen >> 16) & 255).chr(($clen >> 24) & 255)
                .chr($llen & 255).chr(($llen >> 8) & 255).chr(($llen >> 16) & 255).chr(($llen >> 24) & 255)
                .chr(0).chr(0);
            self::$h1open = 0;
            self::$h1name = '';
            self::$h1data = '';
            self::$h1status = 0;

            return self::packPayload(1, $local.$central.$eocd);
        }
        if ('status' === $op) {
            return self::pack(self::$h1status);
        }
        // getStatusString (#35449) — NestedJIT-safe literals only (no match / cross-class).
        if ('status_string' === $op) {
            $code = self::$h1status;
            $msg = 'No error';
            if (0 !== $code) {
                if (9 === $code) {
                    $msg = 'No such file';
                } elseif (8 === $code) {
                    $msg = 'Containing zip archive was closed';
                } elseif (5 === $code) {
                    $msg = 'Read error';
                } elseif (18 === $code) {
                    $msg = 'Invalid argument';
                } else {
                    $msg = 'Unknown status '.$code;
                }
            }

            return self::packPayload(1, $msg);
        }
        if ('status_sys' === $op) {
            return self::pack(0);
        }
        if ('last_id' === $op) {
            return self::pack(self::$h1name !== '' ? 0 : -1);
        }
        if ('num_files' === $op) {
            return self::pack(self::$h1open === 1 && self::$h1name !== '' ? 1 : 0);
        }

        return self::pack(0);
    }

    private static function pack(int $rc): string
    {
        $n = $rc & 0xffffffff;

        return chr($n & 255).chr(($n >> 8) & 255).chr(($n >> 16) & 255).chr(($n >> 24) & 255);
    }

    private static function packPayload(int $rc, string $payload): string
    {
        return self::pack($rc).$payload;
    }
}
