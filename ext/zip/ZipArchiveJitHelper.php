<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

/**
 * ZipArchive NestedJIT helper (#35424 / #35437 / #35440 / #35449 / #35450 / #35455 / #35454 /
 * #35465 / #35466 / #35467 / #35472 / #35476 / #35486 / #35489 / #35491 / #35496 / #35500 /
 * #35504 / #35506 / #35503 / #35508 / #35515 / #35522 / #35537) — CREATE/add/close/get/locate/index/rename/delete/
 * extract/status/count/archive-comment/entry-comment/unchange/replaceFile/setPassword/stat/
 * setCompression/setEncryption/setMtime/setExternalAttributes/setArchiveFlag/addGlob/addPattern path.
 *
 * Two scalar entry slots (no static arrays). Branch on empty-string sentinels — NestedJIT
 * aborts on some static-int comparisons in this helper (#35454).
 *
 * Result encoding: 4-byte LE int32 + optional payload (get/close). Int returns via
 * NestedJIT arrive as ptrtoint of __value__ boxes, so the ABI is string-packed.
 *
 * php-src: ext/zip/php_zip.c — zim_ZipArchive_* (open / addFromString / addFile / addEmptyDir /
 * close / getFromName / locateName / getFromIndex / getNameIndex / getStatusString /
 * renameName / renameIndex / deleteName / deleteIndex / extractTo / count /
 * setArchiveComment / getArchiveComment / setCommentName / getCommentName /
 * setCommentIndex / getCommentIndex / unchangeAll / unchangeArchive / unchangeIndex /
 * unchangeName / replaceFile / setPassword / setCompressionName / setCompressionIndex /
 * setEncryptionName / setEncryptionIndex / setExternalAttributesName / setExternalAttributesIndex /
 * statName / statIndex / setMtimeName / setMtimeIndex / setArchiveFlag / getArchiveFlag /
 * addGlob / addPattern)
 */
final class ZipArchiveJitHelper
{
    /** Packed RETURN_SB after rc: 7×int32 LE + name (#35504 / zim_ZipArchive_stat*). */
    public const STAT_FIELD_BYTES = 28;

    /** addGlob/addPattern false sentinel (sext i64 == -1) (#35537). */
    public const ADDPATHS_FALSE_RC = -1;

    private static int $nextId = 1;

    private static int $h1open = 0;

    private static string $h1name = '';

    private static string $h1data = '';

    private static string $h1name2 = '';

    private static string $h1data2 = '';

    /** EOCD archive comment — php-src zip_set_archive_comment (#35476 / #20386). */
    private static string $h1comment = '';

    /** Per-entry comments for slots 0/1 (#35486 leftover of #35476 / #20386). */
    private static string $h1ecomment = '';

    private static string $h1ecomment2 = '';

    /** Open-time snapshot for unchangeAll / unchangeArchive (#35489 / #20387). */
    private static string $h1snap_name = '';

    private static string $h1snap_data = '';

    private static string $h1snap_name2 = '';

    private static string $h1snap_data2 = '';

    private static string $h1snap_comment = '';

    private static string $h1snap_ecomment = '';

    private static string $h1snap_ecomment2 = '';

    private static int $h1status = 0;

    /** AFL_RDONLY-style session flag (#35478). */
    private static int $h1readonly = 0;

    /** Archive AFL_* bitmask (#35522 / zim_ZipArchive_setArchiveFlag). */
    private static int $h1aflags = 0;

    /** Open-time archive flags for getArchiveFlag(..., FL_UNCHANGED) (#35522). */
    private static int $h1snap_aflags = 0;

    /** Session password for setEncryption* (#35500 / #19873). */
    private static string $h1password = '';

    /** Per-entry compression method for slots 0/1 (#35506 / #20363). CM_DEFAULT stored as CM_STORE. */
    private static int $h1comp = 0;

    private static int $h1comp2 = 0;

    /** Per-entry encryption_method for slots 0/1 (#35503 / #19873). EM_NONE = 0. */
    private static int $h1enc = 0;

    private static int $h1enc2 = 0;

    /** Per-entry encryption_password for slots 0/1 (#35503). */
    private static string $h1encpw = '';

    private static string $h1encpw2 = '';

    /** Per-entry unix mtime for slots 0/1 (#35508 / zim_ZipArchive_setMtime*). 0 → time() in packStat. */
    private static int $h1mtime = 0;

    private static int $h1mtime2 = 0;

    /** Per-entry opsys + external_attr for slots 0/1 (#35515 / #20363). */
    private static int $h1opsys = 3;

    private static int $h1opsys2 = 3;

    private static int $h1attr = 0;

    private static int $h1attr2 = 0;

    /** Scratch paths for addGlob/addPattern return list (#35537) — NestedJIT avoids by-ref arrays. */
    private static string $agP0 = '';

    private static string $agP1 = '';

    private static int $agN = 0;

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
            self::$h1comment = '';
            self::$h1ecomment = '';
            self::$h1ecomment2 = '';
            self::$h1status = 0;
            self::$h1readonly = 0;
            self::$h1aflags = 0;
            self::$h1snap_aflags = 0;
            self::$h1password = '';
            self::$h1comp = 0;
            self::$h1comp2 = 0;
            self::$h1enc = 0;
            self::$h1enc2 = 0;
            self::$h1encpw = '';
            self::$h1encpw2 = '';
            self::$h1mtime = 0;
            self::$h1mtime2 = 0;
            self::$h1opsys = 3;
            self::$h1opsys2 = 3;
            self::$h1attr = 0;
            self::$h1attr2 = 0;
            self::$agP0 = '';
            self::$agP1 = '';
            self::$agN = 0;
            self::snapSave();

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
            self::$h1comment = '';
            self::$h1ecomment = '';
            self::$h1ecomment2 = '';
            self::$h1open = 0;
            self::$h1readonly = 0;
            self::$h1aflags = 0;
            self::$h1snap_aflags = 0;
            self::$h1password = '';
            self::$h1comp = 0;
            self::$h1comp2 = 0;
            self::$h1enc = 0;
            self::$h1enc2 = 0;
            self::$h1encpw = '';
            self::$h1encpw2 = '';
            self::$h1mtime = 0;
            self::$h1mtime2 = 0;
            self::$h1opsys = 3;
            self::$h1opsys2 = 3;
            self::$h1attr = 0;
            self::$h1attr2 = 0;
            self::$agP0 = '';
            self::$agP1 = '';
            self::$agN = 0;
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
                    // EOCD archive comment — bounded scan only (NestedJIT rejects open-ended while, #35476).
                    self::$h1comment = '';
                    $bi = 0;
                    for ($bi = 0; $bi < 64; $bi++) {
                        if ($len < 22 + $bi) {
                            break;
                        }
                        $eoff = $len - 22 - $bi;
                        $sigE = ord($data[$eoff]) | (ord($data[$eoff + 1]) << 8)
                            | (ord($data[$eoff + 2]) << 16) | (ord($data[$eoff + 3]) << 24);
                        if (0x06054b50 === $sigE) {
                            $cmtLen = ord($data[$eoff + 20]) | (ord($data[$eoff + 21]) << 8);
                            if ($cmtLen === $bi && $eoff + 22 + $cmtLen === $len) {
                                if ($cmtLen > 0) {
                                    self::$h1comment = substr($data, $eoff + 22, $cmtLen);
                                }
                                // Entry comments in central directory — unrolled (NestedJIT) (#35493).
                                $cdPos = ord($data[$eoff + 16]) | (ord($data[$eoff + 17]) << 8)
                                    | (ord($data[$eoff + 18]) << 16) | (ord($data[$eoff + 19]) << 24);
                                if ($cdPos + 46 <= $len) {
                                    $sigC = ord($data[$cdPos]) | (ord($data[$cdPos + 1]) << 8)
                                        | (ord($data[$cdPos + 2]) << 16) | (ord($data[$cdPos + 3]) << 24);
                                    if (0x02014b50 === $sigC) {
                                        $nlenC = ord($data[$cdPos + 28]) | (ord($data[$cdPos + 29]) << 8);
                                        $xlenC = ord($data[$cdPos + 30]) | (ord($data[$cdPos + 31]) << 8);
                                        $clenC = ord($data[$cdPos + 32]) | (ord($data[$cdPos + 33]) << 8);
                                        $nameOff = $cdPos + 46;
                                        if ($nameOff + $nlenC + $xlenC + $clenC <= $len) {
                                            if ($clenC > 0) {
                                                self::$h1ecomment = substr(
                                                    $data,
                                                    $nameOff + $nlenC + $xlenC,
                                                    $clenC
                                                );
                                            }
                                            $cdPos2 = $nameOff + $nlenC + $xlenC + $clenC;
                                            if ($cdPos2 + 46 <= $len) {
                                                $sigC2 = ord($data[$cdPos2]) | (ord($data[$cdPos2 + 1]) << 8)
                                                    | (ord($data[$cdPos2 + 2]) << 16)
                                                    | (ord($data[$cdPos2 + 3]) << 24);
                                                if (0x02014b50 === $sigC2) {
                                                    $nlenC2 = ord($data[$cdPos2 + 28])
                                                        | (ord($data[$cdPos2 + 29]) << 8);
                                                    $xlenC2 = ord($data[$cdPos2 + 30])
                                                        | (ord($data[$cdPos2 + 31]) << 8);
                                                    $clenC2 = ord($data[$cdPos2 + 32])
                                                        | (ord($data[$cdPos2 + 33]) << 8);
                                                    $nameOff2 = $cdPos2 + 46;
                                                    if ($nameOff2 + $nlenC2 + $xlenC2 + $clenC2 <= $len
                                                        && $clenC2 > 0) {
                                                        self::$h1ecomment2 = substr(
                                                            $data,
                                                            $nameOff2 + $nlenC2 + $xlenC2,
                                                            $clenC2
                                                        );
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                        }
                    }
                    self::snapSave();

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
                self::$h1ecomment = '';
                self::$h1comp = 0;
                self::$h1mtime = 0;
                self::$h1opsys = 3;
                self::$h1attr = 0;
            } elseif ($s1 === self::$h1name) {
                self::$h1data = $s2;
            } elseif ('' === self::$h1name2) {
                self::$h1name2 = $s1;
                self::$h1data2 = $s2;
                self::$h1ecomment2 = '';
                self::$h1comp2 = 0;
                self::$h1mtime2 = 0;
                self::$h1opsys2 = 3;
                self::$h1attr2 = 0;
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
                self::$h1ecomment = '';
                self::$h1comp = 0;
                self::$h1mtime = 0;
                self::$h1opsys = 3;
                self::$h1attr = 0;
            } elseif ($s1 === self::$h1name) {
                self::$h1status = 10;

                return self::pack(0);
            } elseif ('' === self::$h1name2) {
                self::$h1name2 = $s1;
                self::$h1data2 = '';
                self::$h1ecomment2 = '';
                self::$h1comp2 = 0;
                self::$h1mtime2 = 0;
                self::$h1opsys2 = 3;
                self::$h1attr2 = 0;
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
        if ('fail_inval' === $op) {
            self::$h1status = 18;

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
        // Empty $s2 rejected in IR (#35481) — NestedJIT throw SIGSEGVs under thin AOT.
        if ('rename' === $op) {
            if (1 !== self::$h1open || '' === $s2) {
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
        // Empty $s2 rejected in IR (#35481) — NestedJIT throw SIGSEGVs under thin AOT.
        if ('rename_index' === $op) {
            if (1 !== self::$h1open || '' === $s2) {
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
            self::$h1ecomment = '';
            self::$h1comp = 0;
            self::$h1mtime = 0;
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
            self::$h1ecomment = '';
            self::$h1comp = 0;
            self::$h1mtime = 0;
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
            $ecmt = self::$h1ecomment;
            $ecl = strlen($ecmt);
            $central = chr(0x50).chr(0x4b).chr(0x01).chr(0x02)
                .chr(20).chr(0).chr(20).chr(0).chr(0).chr(0).chr(0).chr(0)
                .chr(0).chr(0).chr(0).chr(0)
                .chr(0).chr(0).chr(0).chr(0)
                .chr($size & 255).chr(($size >> 8) & 255).chr(($size >> 16) & 255).chr(($size >> 24) & 255)
                .chr($size & 255).chr(($size >> 8) & 255).chr(($size >> 16) & 255).chr(($size >> 24) & 255)
                .chr($nl & 255).chr(($nl >> 8) & 255).chr(0).chr(0)
                .chr($ecl & 255).chr(($ecl >> 8) & 255)
                .chr(0).chr(0).chr(0).chr(0).chr(0).chr(0).chr(0).chr(0)
                .chr($loff & 255).chr(($loff >> 8) & 255).chr(($loff >> 16) & 255).chr(($loff >> 24) & 255)
                .$name.$ecmt;
            $countLow = 1;
            if ('' !== self::$h1name2) {
                $name2 = self::$h1name2;
                $content2 = self::$h1data2;
                $size2 = strlen($content2);
                $nl2 = strlen($name2);
                $ecmt2 = self::$h1ecomment2;
                $ecl2 = strlen($ecmt2);
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
                    .chr($nl2 & 255).chr(($nl2 >> 8) & 255).chr(0).chr(0)
                    .chr($ecl2 & 255).chr(($ecl2 >> 8) & 255)
                    .chr(0).chr(0).chr(0).chr(0).chr(0).chr(0).chr(0).chr(0)
                    .chr($loff2 & 255).chr(($loff2 >> 8) & 255).chr(($loff2 >> 16) & 255).chr(($loff2 >> 24) & 255)
                    .$name2.$ecmt2;
                $countLow = 2;
            }
            $clen = strlen($central);
            $llen = strlen($local);
            $comment = self::$h1comment;
            $cmtLen = strlen($comment);
            $eocd = chr(0x50).chr(0x4b).chr(0x05).chr(0x06)
                .chr(0).chr(0).chr(0).chr(0)
                .chr($countLow).chr(0).chr($countLow).chr(0)
                .chr($clen & 255).chr(($clen >> 8) & 255).chr(($clen >> 16) & 255).chr(($clen >> 24) & 255)
                .chr($llen & 255).chr(($llen >> 8) & 255).chr(($llen >> 16) & 255).chr(($llen >> 24) & 255)
                .chr($cmtLen & 255).chr(($cmtLen >> 8) & 255)
                .$comment;
            self::$h1open = 0;
            self::$h1name = '';
            self::$h1data = '';
            self::$h1name2 = '';
            self::$h1data2 = '';
            self::$h1comment = '';
            self::$h1ecomment = '';
            self::$h1ecomment2 = '';
            self::$h1password = '';
            self::$h1enc = 0;
            self::$h1enc2 = 0;
            self::$h1encpw = '';
            self::$h1encpw2 = '';
            self::$h1comp = 0;
            self::$h1comp2 = 0;
            self::$h1mtime = 0;
            self::$h1mtime2 = 0;
            self::$h1opsys = 3;
            self::$h1opsys2 = 3;
            self::$h1attr = 0;
            self::$h1attr2 = 0;
            self::$h1status = 0;

            return self::packPayload(1, $local.$central.$eocd);
        }
        // setArchiveComment / getArchiveComment (#35476 leftover of #35472 / #20386).
        // Short op names — NestedJIT long string constants can mis-bind (#35476).
        if ('sac' === $op) {
            if (1 !== self::$h1open) {
                throw new \ValueError('Invalid or uninitialized Zip object');
            }
            // Length guard omitted in NestedJIT path — Zend VM still enforces 65535 (#20386).
            self::$h1comment = $s1;
            self::$h1status = 0;

            return self::pack(1);
        }
        if ('gac' === $op) {
            if (1 !== self::$h1open) {
                throw new \ValueError('Invalid or uninitialized Zip object');
            }
            self::$h1status = 0;
            if ('' === self::$h1comment) {
                return self::pack(0);
            }

            return self::packPayload(1, self::$h1comment);
        }
        // Entry comments (#35486 leftover of #35476 / #20386) — short ops scn/gcn/sci/gci.
        // Empty $name rejected in IR (NestedJIT throw SIGSEGVs under thin AOT — peer #35481).
        if ('scn' === $op) {
            if (1 !== self::$h1open || '' === $s1) {
                self::$h1status = 9;

                return self::pack(0);
            }
            if ('' !== self::$h1name && $s1 === self::$h1name) {
                self::$h1ecomment = $s2;
                self::$h1status = 0;

                return self::pack(1);
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                self::$h1ecomment2 = $s2;
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        if ('gcn' === $op) {
            if (1 !== self::$h1open || '' === $s1) {
                self::$h1status = 9;

                return self::pack(0);
            }
            if ('' !== self::$h1name && $s1 === self::$h1name) {
                self::$h1status = 0;

                return self::packPayload(1, self::$h1ecomment);
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                self::$h1status = 0;

                return self::packPayload(1, self::$h1ecomment2);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        if ('sci' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 18;

                return self::pack(0);
            }
            if (0 === $a && '' !== self::$h1name) {
                self::$h1ecomment = $s1;
                self::$h1status = 0;

                return self::pack(1);
            }
            if (1 === $a && '' !== self::$h1name2) {
                self::$h1ecomment2 = $s1;
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 18;

            return self::pack(0);
        }
        if ('gci' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 18;

                return self::pack(0);
            }
            if (0 === $a && '' !== self::$h1name) {
                self::$h1status = 0;

                return self::packPayload(1, self::$h1ecomment);
            }
            if (1 === $a && '' !== self::$h1name2) {
                self::$h1status = 0;

                return self::packPayload(1, self::$h1ecomment2);
            }
            self::$h1status = 18;

            return self::pack(0);
        }
        // clearError — reset h1status to ER_OK (#35531 leftover of #35527 / #20378).
        if ('ce' === $op) {
            self::$h1status = 0;

            return self::pack(1);
        }
        // registerProgressCallback / registerCancelCallback — open + success (#35539 / #20378).
        // NestedJIT cannot store PHP callables; registration still returns true when open
        // (callback invoke during mutate/close remains VM-only until a later leftover).
        if ('rpc' === $op || 'rcc' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            self::$h1status = 0;

            return self::pack(1);
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
        // setReadOnly — $a != 0 ⇒ readonly (#35478); keep AFL_RDONLY bit in sync (#35522).
        if ('set_readonly' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if (0 !== $a) {
                self::$h1readonly = 1;
                self::$h1aflags = self::$h1aflags | 2;
            } else {
                self::$h1readonly = 0;
                self::$h1aflags = self::$h1aflags & ~2;
            }
            self::$h1status = 0;

            return self::pack(1);
        }
        // setArchiveFlag / getArchiveFlag — AFL_* bits (#35522 / #21831).
        // $a = flag; $b = value (saf) or FL_* (gaf). Known flags: 2/4/8/16.
        if ('saf' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            $known = false;
            if (2 === $a) {
                $known = true;
            } elseif (4 === $a) {
                $known = true;
            } elseif (8 === $a) {
                $known = true;
            } elseif (16 === $a) {
                $known = true;
            }
            if (!$known) {
                self::$h1status = 18;

                return self::pack(0);
            }
            if (0 === $b) {
                if (2 === $a) {
                    $has = self::$h1aflags & 2;
                    if (0 !== $has) {
                        self::$h1status = 18;

                        return self::pack(0);
                    }
                }
                self::$h1aflags = self::$h1aflags & ~$a;
                if (2 === $a) {
                    self::$h1readonly = 0;
                }
            } else {
                self::$h1aflags = self::$h1aflags | $a;
                if (2 === $a) {
                    self::$h1readonly = 1;
                }
            }
            self::$h1status = 0;

            return self::pack(1);
        }
        if ('gaf' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            $unchanged = $b & 8;
            if (0 !== $unchanged) {
                $bits = self::$h1snap_aflags;
            } else {
                $bits = self::$h1aflags;
            }
            self::$h1status = 0;
            $masked = $bits & $a;
            if (0 !== $masked) {
                return self::pack(1);
            }

            return self::pack(0);
        }
        // unchangeAll — restore open snapshot (#35489 leftover of #35486 / #20387).
        if ('ua' === $op) {
            if (1 !== self::$h1open) {
                throw new \ValueError('Invalid or uninitialized Zip object');
            }
            self::$h1name = self::$h1snap_name;
            self::$h1data = self::$h1snap_data;
            self::$h1name2 = self::$h1snap_name2;
            self::$h1data2 = self::$h1snap_data2;
            self::$h1comment = self::$h1snap_comment;
            self::$h1ecomment = self::$h1snap_ecomment;
            self::$h1ecomment2 = self::$h1snap_ecomment2;
            self::$h1status = 0;

            return self::packPayload(1, self::$h1comment);
        }
        // unchangeArchive — restore archive comment only (#35489 / #20387).
        if ('uar' === $op) {
            if (1 !== self::$h1open) {
                throw new \ValueError('Invalid or uninitialized Zip object');
            }
            self::$h1comment = self::$h1snap_comment;
            self::$h1status = 0;

            return self::packPayload(1, self::$h1comment);
        }
        // unchangeIndex — restore slot from open snapshot (#35491 / php-src zip_unchange).
        if ('uci' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if (0 === $a) {
                return self::pack(self::unchangeSlot0() ? 1 : 0);
            }
            if (1 === $a) {
                return self::pack(self::unchangeSlot1() ? 1 : 0);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        // unchangeName — restore by current name (#35491 / php-src zip_unchange).
        if ('ucn' === $op) {
            if (1 !== self::$h1open || '' === $s1) {
                self::$h1status = 9;

                return self::pack(0);
            }
            if ('' !== self::$h1name && $s1 === self::$h1name) {
                return self::pack(self::unchangeSlot0() ? 1 : 0);
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                return self::pack(self::unchangeSlot1() ? 1 : 0);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        // replaceFile — replace slot data, keep name (#35496 / php-src zim_ZipArchive_replaceFile).
        // Content is read in IR via file_get_contents; $s2 is the new bytes. $a = index.
        if ('rpl' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if (0 === $a && '' !== self::$h1name) {
                self::$h1data = $s2;
                self::$h1status = 0;

                return self::pack(1);
            }
            if (1 === $a && '' !== self::$h1name2) {
                self::$h1data2 = $s2;
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        // setPassword — store session password (#35500 / zim_ZipArchive_setPassword).
        // Empty password → false (php-src). Short op spw.
        if ('spw' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if ('' === $s1) {
                self::$h1status = 0;

                return self::pack(0);
            }
            self::$h1password = $s1;
            self::$h1status = 0;

            return self::pack(1);
        }
        // statName — RETURN_SB packed payload (#35504 / zim_ZipArchive_statName). Short op stn.
        if ('stn' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if ('' !== self::$h1name && $s1 === self::$h1name) {
                self::$h1status = 0;

                return self::packStat(0, self::$h1name, self::$h1data);
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                self::$h1status = 0;

                return self::packStat(1, self::$h1name2, self::$h1data2);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        // statIndex — RETURN_SB packed payload (#35504 / zim_ZipArchive_statIndex). Short op sti.
        if ('sti' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if (0 === $a) {
                if ('' === self::$h1name) {
                    self::$h1status = 18;

                    return self::pack(0);
                }
                self::$h1status = 0;

                return self::packStat(0, self::$h1name, self::$h1data);
            }
            if (1 === $a) {
                if ('' === self::$h1name2) {
                    self::$h1status = 18;

                    return self::pack(0);
                }
                self::$h1status = 0;

                return self::packStat(1, self::$h1name2, self::$h1data2);
            }
            self::$h1status = 18;

            return self::pack(0);
        }
        // setCompressionName — $a = method, $s1 = name (#35506 / zim_ZipArchive_setCompressionName).
        // CM_DEFAULT (-1) → CM_STORE. Unsupported encode methods → ER_COMPNOTSUPP.
        if ('cpm' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if ('' === $s1) {
                self::$h1status = 18;

                return self::pack(0);
            }
            if (0 !== $a && -1 !== $a) {
                self::$h1status = 16;

                return self::pack(0);
            }
            $normalized = -1 === $a ? 0 : $a;
            if ('' !== self::$h1name && $s1 === self::$h1name) {
                self::$h1comp = $normalized;
                self::$h1status = 0;

                return self::pack(1);
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                self::$h1comp2 = $normalized;
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        // setCompressionIndex — $a = index, $b = method (#35506 / zim_ZipArchive_setCompressionIndex).
        if ('cpi' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if (0 !== $b && -1 !== $b) {
                self::$h1status = 16;

                return self::pack(0);
            }
            $normalized = -1 === $b ? 0 : $b;
            if (0 === $a && '' !== self::$h1name) {
                self::$h1comp = $normalized;
                self::$h1status = 0;

                return self::pack(1);
            }
            if (1 === $a && '' !== self::$h1name2) {
                self::$h1comp2 = $normalized;
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 18;

            return self::pack(0);
        }
        // setEncryptionIndex — $a=index, $b=method; sei=omit pw, seip=password in $s1 (#35503).
        // setEncryptionName lowers via locate + sei/seip. Keep branches NestedJIT-safe (peer sci/uci).
        if ('sei' === $op || 'seip' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            $hasPw = 'seip' === $op;
            $use = $hasPw ? $s1 : self::$h1password;
            if (0 === $a && '' !== self::$h1name) {
                if (0 === $b) {
                    self::$h1enc = 0;
                    self::$h1encpw = '';
                } else {
                    self::$h1enc = $b;
                    self::$h1encpw = '' !== $use ? $use : '';
                }
                self::$h1status = 0;

                return self::pack(1);
            }
            if (1 === $a && '' !== self::$h1name2) {
                if (0 === $b) {
                    self::$h1enc2 = 0;
                    self::$h1encpw2 = '';
                } else {
                    self::$h1enc2 = $b;
                    self::$h1encpw2 = '' !== $use ? $use : '';
                }
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 4;

            return self::pack(0);
        }
        // setMtimeName / setMtimeIndex — per-slot unix mtime (#35508 / zim_ZipArchive_setMtime*).
        // Empty name rejected in IR. $b = timestamp; smn uses $s1 name; smi uses $a index.
        if ('smn' === $op) {
            if (1 !== self::$h1open || '' === $s1) {
                self::$h1status = 9;

                return self::pack(0);
            }
            if ('' !== self::$h1name && $s1 === self::$h1name) {
                self::$h1mtime = $b;
                self::$h1status = 0;

                return self::pack(1);
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                self::$h1mtime2 = $b;
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        if ('smi' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 18;

                return self::pack(0);
            }
            if (0 === $a && '' !== self::$h1name) {
                self::$h1mtime = $b;
                self::$h1status = 0;

                return self::pack(1);
            }
            if (1 === $a && '' !== self::$h1name2) {
                self::$h1mtime2 = $b;
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 18;

            return self::pack(0);
        }

        // setExternalAttributesName — $s1=name, $a=opsys, $b=attr (#35515 / #20363).
        if ('ean' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if ('' === $s1) {
                self::$h1status = 9;

                return self::pack(0);
            }
            // opsys is 0..255; mask without signed compares NestedJIT dislikes.
            $opsys = $a & 255;
            $attr = $b;
            if ('' !== self::$h1name && $s1 === self::$h1name) {
                self::$h1opsys = $opsys;
                self::$h1attr = $attr;
                self::$h1status = 0;

                return self::pack(1);
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                self::$h1opsys2 = $opsys;
                self::$h1attr2 = $attr;
                self::$h1status = 0;

                return self::pack(1);
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        // getExternalAttributesName / Index — payload opsys+attr int32 LE (#35527 / #20363).
        if ('gan' === $op) {
            if (1 !== self::$h1open || '' === $s1) {
                self::$h1status = 9;

                return self::pack(0);
            }
            if ('' !== self::$h1name && $s1 === self::$h1name) {
                self::$h1status = 0;

                return self::packPayload(1, self::pack(self::$h1opsys).self::pack(self::$h1attr));
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                self::$h1status = 0;

                return self::packPayload(1, self::pack(self::$h1opsys2).self::pack(self::$h1attr2));
            }
            self::$h1status = 9;

            return self::pack(0);
        }
        if ('gai' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 18;

                return self::pack(0);
            }
            if (0 === $a && '' !== self::$h1name) {
                self::$h1status = 0;

                return self::packPayload(1, self::pack(self::$h1opsys).self::pack(self::$h1attr));
            }
            if (1 === $a && '' !== self::$h1name2) {
                self::$h1status = 0;

                return self::packPayload(1, self::pack(self::$h1opsys2).self::pack(self::$h1attr2));
            }
            self::$h1status = 18;

            return self::pack(0);
        }

        // getStream — $s1=name; payload = entry bytes (#35534 / #20378).
        // Directory reject (trailing "/") is enforced in IR when needed; NestedJIT
        // dislikes substr($s, -1) in this helper.
        if ('gstr' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if ('' === $s1) {
                self::$h1status = 9;

                return self::pack(0);
            }
            if ('' !== self::$h1name && $s1 === self::$h1name) {
                self::$h1status = 0;

                return self::packPayload(1, self::$h1data);
            }
            if ('' !== self::$h1name2 && $s1 === self::$h1name2) {
                self::$h1status = 0;

                return self::packPayload(1, self::$h1data2);
            }
            self::$h1status = 9;

            return self::pack(0);
        }

        // getStreamIndex — $a=index (#35534 / #20378).
        if ('gsi' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(0);
            }
            if (0 === $a) {
                if ('' === self::$h1name) {
                    self::$h1status = 18;

                    return self::pack(0);
                }
                self::$h1status = 0;

                return self::packPayload(1, self::$h1data);
            }
            if (1 === $a) {
                if ('' === self::$h1name2) {
                    self::$h1status = 18;

                    return self::pack(0);
                }
                self::$h1status = 0;

                return self::packPayload(1, self::$h1data2);
            }
            self::$h1status = 18;

            return self::pack(0);
        }

        // addGlob — $s1=pattern, $a=flags; rc=count; paths via agp (#35537 / #20387).
        // NestedJIT: no open-ended foreach — unroll first 8 glob hits into ≤2 slots.
        if ('ag' === $op) {
            if (1 !== self::$h1open) {
                self::$h1status = 8;

                return self::pack(self::ADDPATHS_FALSE_RC);
            }
            if ('' === $s1) {
                self::$h1status = 18;

                return self::pack(self::ADDPATHS_FALSE_RC);
            }
            $found = @\glob($s1, $a);
            if (false === $found) {
                self::$h1status = 18;

                return self::pack(self::ADDPATHS_FALSE_RC);
            }
            self::$agP0 = '';
            self::$agP1 = '';
            self::$agN = 0;
            if (self::agAbsorb($found, 0) < 0
                || self::agAbsorb($found, 1) < 0
                || self::agAbsorb($found, 2) < 0
                || self::agAbsorb($found, 3) < 0
                || self::agAbsorb($found, 4) < 0
                || self::agAbsorb($found, 5) < 0
                || self::agAbsorb($found, 6) < 0
                || self::agAbsorb($found, 7) < 0
            ) {
                return self::pack(self::ADDPATHS_FALSE_RC);
            }
            self::$h1status = 0;

            return self::pack(self::$agN);
        }

        // addPattern — $s1=PCRE, $s2=dir (#35537 / #20387).
        if ('ap' === $op) {
            return self::addPatternScan($s1, $s2, false);
        }

        // addPattern suffix — $s1=literal suffix (IR-stripped from /^…$/); no preg (#35537).
        if ('aps' === $op) {
            return self::addPatternScan($s1, $s2, true);
        }

        // Fetch addGlob/addPattern path by index — payload is the path string (#35537).
        if ('agp' === $op) {
            $path = '';
            if (0 === $a) {
                $path = self::$agP0;
            } elseif (1 === $a) {
                $path = self::$agP1;
            }
            if ('' === $path) {
                return self::pack(0);
            }

            return self::packPayload(1, $path);
        }

        return self::pack(0);
    }

    /**
     * Shared addPattern scandir walk (#35537).
     *
     * @param bool $suffixMode when true, $pattern is a literal suffix (not PCRE)
     */
    private static function addPatternScan(string $pattern, string $path, bool $suffixMode): string
    {
        if (1 !== self::$h1open) {
            self::$h1status = 8;

            return self::pack(self::ADDPATHS_FALSE_RC);
        }
        if ('' === $pattern) {
            self::$h1status = 18;

            return self::pack(self::ADDPATHS_FALSE_RC);
        }
        $list = @\scandir($path);
        if (false === $list) {
            self::$h1status = 9;

            return self::pack(self::ADDPATHS_FALSE_RC);
        }
        self::$agP0 = '';
        self::$agP1 = '';
        self::$agN = 0;
        if (self::apAbsorb($list, $pattern, $path, 0, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 1, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 2, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 3, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 4, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 5, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 6, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 7, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 8, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 9, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 10, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 11, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 12, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 13, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 14, $suffixMode) < 0
            || self::apAbsorb($list, $pattern, $path, 15, $suffixMode) < 0
        ) {
            return self::pack(self::ADDPATHS_FALSE_RC);
        }
        self::$h1status = 0;

        return self::pack(self::$agN);
    }

    /**
     * Absorb one glob hit into slots (#35537). Returns 0 ok, or -1 on hard fail.
     *
     * @param list<string>|array<int|string, mixed> $found
     */
    private static function agAbsorb(array $found, int $i): int
    {
        if (self::$agN >= 2 || !isset($found[$i]) || !\is_string($found[$i]) || '' === $found[$i]) {
            return 0;
        }

        return self::agAddOne($found[$i]);
    }

    /**
     * Absorb one scandir entry for addPattern (#35537). Returns 0 ok, or -1 on hard fail.
     *
     * @param list<string>|array<int|string, mixed> $list
     */
    private static function apAbsorb(
        array $list,
        string $pattern,
        string $path,
        int $i,
        bool $suffixMode
    ): int {
        if (self::$agN >= 2 || !isset($list[$i]) || !\is_string($list[$i])) {
            return 0;
        }
        $file = $list[$i];
        if ('.' === $file || '..' === $file) {
            return 0;
        }
        if ($suffixMode) {
            $fl = \strlen($file);
            $sl = \strlen($pattern);
            if ($fl < $sl) {
                return 0;
            }
            // Positive-offset substr — NestedJIT rejects substr($s, -1) (#35476 peer).
            if (\substr($file, $fl - $sl) !== $pattern) {
                return 0;
            }
        } else {
            $m = @\preg_match($pattern, $file);
            if (false === $m) {
                self::$h1status = 18;

                return -1;
            }
            if (1 !== $m) {
                return 0;
            }
        }
        $base = $path;
        $blen = \strlen($base);
        if ($blen > 0) {
            $last = \ord($base[$blen - 1]);
            if (47 !== $last && 92 !== $last) {
                $base = $base.'/';
            }
        }
        $full = $base.$file;
        if (!@\is_file($full)) {
            return 0;
        }

        return self::agAddOne($full);
    }

    /**
     * Add one filesystem path into NestedJIT slots 0/1 + path scratch (#35537).
     */
    private static function agAddOne(string $filepath): int
    {
        if (1 === self::$h1readonly) {
            self::$h1status = 25;

            return -1;
        }
        $data = @\file_get_contents($filepath);
        if (false === $data) {
            return 0;
        }
        // Entry name = full path (VM entryNameFromOptions with empty options, #20387).
        if ('' === self::$h1name) {
            self::$h1name = $filepath;
            self::$h1data = $data;
            self::$h1ecomment = '';
            self::$h1comp = 0;
            self::$h1mtime = 0;
            self::$h1opsys = 3;
            self::$h1attr = 0;
            self::$agP0 = $filepath;
            self::$agN = 1;

            return 0;
        }
        if ($filepath === self::$h1name) {
            self::$h1data = $data;
            self::$agP0 = $filepath;
            if (self::$agN < 1) {
                self::$agN = 1;
            }

            return 0;
        }
        if ('' === self::$h1name2) {
            self::$h1name2 = $filepath;
            self::$h1data2 = $data;
            self::$h1ecomment2 = '';
            self::$h1comp2 = 0;
            self::$h1mtime2 = 0;
            self::$h1opsys2 = 3;
            self::$h1attr2 = 0;
            self::$agP1 = $filepath;
            self::$agN = 2;

            return 0;
        }
        if ($filepath === self::$h1name2) {
            self::$h1data2 = $data;
            self::$agP1 = $filepath;
            if (self::$agN < 2) {
                self::$agN = 2;
            }

            return 0;
        }
        // Slot cap — NestedJIT honest subset keeps ≤2 entries (#35454).
        return 0;
    }

    /**
     * Pack RETURN_SB fields for IR hashtable materialization (#35504).
     *
     * Layout after rc int32: index,crc,size,mtime,comp_size,comp_method,encryption_method + name.
     */
    private static function packStat(int $index, string $name, string $data): string
    {
        $size = strlen($data);
        $crc = crc32($data);
        if ($crc < 0) {
            $crc += 0x100000000;
        }
        $comp = 0 === $index ? self::$h1comp : self::$h1comp2;
        $enc = 0 === $index ? self::$h1enc : self::$h1enc2;
        $mtime = 0 === $index ? self::$h1mtime : self::$h1mtime2;
        if (0 === $mtime) {
            $mtime = time();
        }
        $payload = self::pack($index)
            .self::pack((int) $crc)
            .self::pack($size)
            .self::pack($mtime)
            .self::pack($size)
            .self::pack($comp)
            .self::pack($enc)
            .$name;

        return self::packPayload(1, $payload);
    }

    private static function snapSave(): void
    {
        self::$h1snap_name = self::$h1name;
        self::$h1snap_data = self::$h1data;
        self::$h1snap_name2 = self::$h1name2;
        self::$h1snap_data2 = self::$h1data2;
        self::$h1snap_comment = self::$h1comment;
        self::$h1snap_ecomment = self::$h1ecomment;
        self::$h1snap_ecomment2 = self::$h1ecomment2;
        self::$h1snap_aflags = self::$h1aflags;
    }

    /**
     * Restore slot 0 from open snapshot. Empty snap + occupied slot ⇒ remove (added after open).
     */
    private static function unchangeSlot0(): bool
    {
        if ('' === self::$h1snap_name) {
            if ('' === self::$h1name) {
                self::$h1status = 9;

                return false;
            }
            self::$h1name = '';
            self::$h1data = '';
            self::$h1ecomment = '';
            self::$h1status = 0;

            return true;
        }
        self::$h1name = self::$h1snap_name;
        self::$h1data = self::$h1snap_data;
        self::$h1ecomment = self::$h1snap_ecomment;
        self::$h1status = 0;

        return true;
    }

    /** Restore slot 1 from open snapshot (#35491). */
    private static function unchangeSlot1(): bool
    {
        if ('' === self::$h1snap_name2) {
            if ('' === self::$h1name2) {
                self::$h1status = 9;

                return false;
            }
            self::$h1name2 = '';
            self::$h1data2 = '';
            self::$h1ecomment2 = '';
            self::$h1status = 0;

            return true;
        }
        self::$h1name2 = self::$h1snap_name2;
        self::$h1data2 = self::$h1snap_data2;
        self::$h1ecomment2 = self::$h1snap_ecomment2;
        self::$h1status = 0;

        return true;
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
