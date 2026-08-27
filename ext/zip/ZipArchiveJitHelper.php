<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

/**
 * ZipArchive NestedJIT helper (#35424 / #35437 / #35440 / #35449 / #35450 / #35455 / #35454 /
 * #35465 / #35466 / #35467 / #35472) — CREATE/add/close/get/locate/index/rename/delete/extract/
 * status/count path.
 *
 * Two scalar entry slots (no static arrays). Branch on empty-string sentinels — NestedJIT
 * aborts on some static-int comparisons in this helper (#35454).
 *
 * Result encoding: 4-byte LE int32 + optional payload (get/close). Int returns via
 * NestedJIT arrive as ptrtoint of __value__ boxes, so the ABI is string-packed.
 *
 * php-src: ext/zip/php_zip.c — zim_ZipArchive_* (open / addFromString / addFile / addEmptyDir /
 * close / getFromName / locateName / getFromIndex / getNameIndex / getStatusString /
 * renameName / renameIndex / deleteName / deleteIndex / extractTo / count)
 */
final class ZipArchiveJitHelper
{
    private static int $nextId = 1;

    private static int $h1open = 0;

    private static string $h1name = '';

    private static string $h1data = '';

    private static string $h1name2 = '';

    private static string $h1data2 = '';

    private static int $h1status = 0;

    /** AFL_RDONLY-style session flag (#35478). */
    private static int $h1readonly = 0;

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
            self::$h1name2 = '';
            self::$h1data2 = '';
            self::$h1status = 0;
            self::$h1readonly = 0;

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
            self::$h1name2 = '';
            self::$h1data2 = '';
            self::$h1open = 0;
            self::$h1readonly = 0;
            if ($len >= 30 && 0x04034b50 === (ord($data[0]) | (ord($data[1]) << 8) | (ord($data[2]) << 16) | (ord($data[3]) << 24))) {
                $nlen = ord($data[26]) | (ord($data[27]) << 8);
                $xlen = ord($data[28]) | (ord($data[29]) << 8);
                $sz = ord($data[22]) | (ord($data[23]) << 8) | (ord($data[24]) << 16) | (ord($data[25]) << 24);
                $off = 30 + $nlen + $xlen;
                if ($off + $sz <= $len) {
                    self::$h1name = substr($data, 30, $nlen);
                    self::$h1data = substr($data, $off, $sz);
                    self::$h1name2 = '';
                    self::$h1data2 = '';
                    $pos = $off + $sz;
                    if ($pos + 30 <= $len) {
                        $sig2 = ord($data[$pos]) | (ord($data[$pos + 1]) << 8) | (ord($data[$pos + 2]) << 16) | (ord($data[$pos + 3]) << 24);
                        if (0x04034b50 === $sig2) {
                            $nlen2 = ord($data[$pos + 26]) | (ord($data[$pos + 27]) << 8);
                            $xlen2 = ord($data[$pos + 28]) | (ord($data[$pos + 29]) << 8);
                            $sz2 = ord($data[$pos + 22]) | (ord($data[$pos + 23]) << 8) | (ord($data[$pos + 24]) << 16) | (ord($data[$pos + 25]) << 24);
                            $off2 = $pos + 30 + $nlen2 + $xlen2;
                            if ($off2 + $sz2 <= $len) {
                                self::$h1name2 = substr($data, $pos + 30, $nlen2);
                                self::$h1data2 = substr($data, $off2, $sz2);
                            }
                        }
                    }
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
            // Empty-name sentinel for slot occupancy (#35454 NestedJIT).
            if ('' === self::$h1name) {
                self::$h1name = $s1;
                self::$h1data = $s2;
            } elseif ($s1 === self::$h1name) {
                self::$h1data = $s2;
            } elseif ('' === self::$h1name2) {
                self::$h1name2 = $s1;
                self::$h1data2 = $s2;
            } elseif ($s1 === self::$h1name2) {
                self::$h1data2 = $s2;
            } else {
                self::$h1status = 18;

                return self::pack(0);
            }
            self::$h1status = 0;

            return self::pack(1);
        }
        // addir — addEmptyDir after IR appends "/" (#35465). Same slots as add; ER_EXISTS on dup.
        if ('addir' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if ('' === $s1) {
                return self::pack(0);
            }
            if ('' === self::$h1name) {
                self::$h1name = $s1;
                self::$h1data = '';
            } elseif ($s1 === self::$h1name) {
                self::$h1status = 10;

                return self::pack(0);
            } elseif ('' === self::$h1name2) {
                self::$h1name2 = $s1;
                self::$h1data2 = '';
            } elseif ($s1 === self::$h1name2) {
                self::$h1status = 10;

                return self::pack(0);
            } else {
                self::$h1status = 18;

                return self::pack(0);
            }
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
            if ($s1 === self::$h1name) {
                return self::packPayload(1, self::$h1data);
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                return self::packPayload(1, self::$h1data2);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        // locateName — index 0/1 or -1 miss (#35437 / #35454).
        if ('locate' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 9;

                return self::pack(-1);
            }
            if ('' !== self::$h1name && $s1 === self::$h1name) {
                self::$h1status = 0;

                return self::pack(0);
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 9;

            return self::pack(-1);
        }
        // getFromIndex (#35437 / #35454).
        if ('get_index' === $op) {
            if (1 !== self::$h1open || '' === self::$h1name) {
                self::$h1status = 9;

                return self::pack(0);
            }
            if (0 === $a) {
                self::$h1status = 0;

                return self::packPayload(1, self::$h1data);
            }
            if (1 === $a && '' !== self::$h1name2) {
                self::$h1status = 0;

                return self::packPayload(1, self::$h1data2);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        // getNameIndex (#35440 / #35454).
        if ('name_index' === $op) {
            if (1 !== self::$h1open || '' === self::$h1name) {
                self::$h1status = 9;

                return self::pack(0);
            }
            if (0 === $a) {
                self::$h1status = 0;

                return self::packPayload(1, self::$h1name);
            }
            if (1 === $a && '' !== self::$h1name2) {
                self::$h1status = 0;

                return self::packPayload(1, self::$h1name2);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        // renameName — name swap on either slot (#35450 leftover of #35424 / #35454).
        if ('rename' === $op) {
            if ('' === $s2) {
                // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
                throw new \ValueError(
                    'ZipArchive::renameName(): Argument #2 ($new_name) must not be empty'
                );
            }
            if (1 !== self::$h1open) {
                self::$h1status = 9;

                return self::pack(0);
            }
            if ('' !== self::$h1name && $s1 === self::$h1name) {
                self::$h1name = $s2;
                self::$h1status = 0;

                return self::pack(1);
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                self::$h1name2 = $s2;
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        // renameIndex — rename by slot index 0/1 (#35472 leftover of #35450 / #35454).
        if ('rename_index' === $op) {
            if ('' === $s2) {
                throw new \ValueError(
                    'ZipArchive::renameIndex(): Argument #2 ($new_name) must not be empty'
                );
            }
            if (1 !== self::$h1open) {
                self::$h1status = 9;

                return self::pack(0);
            }
            if (0 === $a && '' !== self::$h1name) {
                self::$h1name = $s2;
                self::$h1status = 0;

                return self::pack(1);
            }
            if (1 === $a && '' !== self::$h1name2) {
                self::$h1name2 = $s2;
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 9;

            return self::pack(0);
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
        // extractTo — write entry slots under $s1 (pathto); optional $s2 name filter (#35467).
        // @file_put_contents NestedJIT libc leaf (peer FtpTransferJitHelper / #30127).
        if ('extract' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if ('' === $s1) {
                self::$h1status = 11;

                return self::pack(0);
            }
            $base = $s1;
            $filter = $s2;
            $wrote = 0;
            if ('' !== self::$h1name && ('' === $filter || $filter === self::$h1name)) {
                $target = $base.'/'.self::$h1name;
                $n = @\file_put_contents($target, self::$h1data);
                if (false === $n) {
                    self::$h1status = 6;

                    return self::pack(0);
                }
                $wrote = 1;
            }
            if ('' !== self::$h1name2 && ('' === $filter || $filter === self::$h1name2)) {
                $target2 = $base.'/'.self::$h1name2;
                $n2 = @\file_put_contents($target2, self::$h1data2);
                if (false === $n2) {
                    self::$h1status = 6;

                    return self::pack(0);
                }
                $wrote = 1;
            }
            if ('' !== $filter && 0 === $wrote) {
                self::$h1status = 9;

                return self::pack(0);
            }
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
            $countLow = 1;
            if ('' !== self::$h1name2) {
                $name2 = self::$h1name2;
                $content2 = self::$h1data2;
                $size2 = strlen($content2);
                $nl2 = strlen($name2);
                $loff2 = strlen($local);
                $local .= chr(0x50).chr(0x4b).chr(0x03).chr(0x04)
                    .chr(20).chr(0).chr(0).chr(0).chr(0).chr(0)
                    .chr(0).chr(0).chr(0).chr(0)
                    .chr(0).chr(0).chr(0).chr(0)
                    .chr($size2 & 255).chr(($size2 >> 8) & 255).chr(($size2 >> 16) & 255).chr(($size2 >> 24) & 255)
                    .chr($size2 & 255).chr(($size2 >> 8) & 255).chr(($size2 >> 16) & 255).chr(($size2 >> 24) & 255)
                    .chr($nl2 & 255).chr(($nl2 >> 8) & 255).chr(0).chr(0)
                    .$name2.$content2;
                $central .= chr(0x50).chr(0x4b).chr(0x01).chr(0x02)
                    .chr(20).chr(0).chr(20).chr(0).chr(0).chr(0).chr(0).chr(0)
                    .chr(0).chr(0).chr(0).chr(0)
                    .chr(0).chr(0).chr(0).chr(0)
                    .chr($size2 & 255).chr(($size2 >> 8) & 255).chr(($size2 >> 16) & 255).chr(($size2 >> 24) & 255)
                    .chr($size2 & 255).chr(($size2 >> 8) & 255).chr(($size2 >> 16) & 255).chr(($size2 >> 24) & 255)
                    .chr($nl2 & 255).chr(($nl2 >> 8) & 255).chr(0).chr(0).chr(0).chr(0)
                    .chr(0).chr(0).chr(0).chr(0).chr(0).chr(0).chr(0).chr(0)
                    .chr($loff2 & 255).chr(($loff2 >> 8) & 255).chr(($loff2 >> 16) & 255).chr(($loff2 >> 24) & 255)
                    .$name2;
                $countLow = 2;
            }
            $clen = strlen($central);
            $llen = strlen($local);
            $eocd = chr(0x50).chr(0x4b).chr(0x05).chr(0x06)
                .chr(0).chr(0).chr(0).chr(0)
                .chr($countLow).chr(0).chr($countLow).chr(0)
                .chr($clen & 255).chr(($clen >> 8) & 255).chr(($clen >> 16) & 255).chr(($clen >> 24) & 255)
                .chr($llen & 255).chr(($llen >> 8) & 255).chr(($llen >> 16) & 255).chr(($llen >> 24) & 255)
                .chr(0).chr(0);
            self::$h1open = 0;
            self::$h1name = '';
            self::$h1data = '';
            self::$h1name2 = '';
            self::$h1data2 = '';
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
                } elseif (10 === $code) {
                    $msg = 'File already exists';
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
            if ('' === self::$h1name) {
                return self::pack(-1);
            }
            if ('' !== self::$h1name2) {
                return self::pack(1);
            }

            return self::pack(0);
        }
        if ('num_files' === $op) {
            if (1 !== self::$h1open || '' === self::$h1name) {
                return self::pack(0);
            }
            if ('' === self::$h1name2) {
                return self::pack(1);
            }

            return self::pack(2);
        }
        // isWritable — open && !readonly (#35478 leftover of #35424 / #20412).
        if ('is_writable' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            self::$h1status = 0;

            return self::pack(0 === self::$h1readonly ? 1 : 0);
        }
        // setReadOnly — $a != 0 ⇒ readonly (#35478).
        if ('set_readonly' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            self::$h1readonly = 0 !== $a ? 1 : 0;
            self::$h1status = 0;

            return self::pack(1);
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
