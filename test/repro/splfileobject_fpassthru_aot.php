<?php
/**
 * #33358 — AOT SplFileObject::fpassthru via live __spl_fd (peer #33354 / #33332).
 * Thin AOT __compiler_fpassthru writes fd 1 (not PHP OB) — assert on process stdout.
 */
$p = sys_get_temp_dir().'/phpc_sfo_33358_'.getmypid().'.txt';
file_put_contents($p, 'hi');
$o = new SplFileObject($p, 'r');
echo "BEGIN\n";
$n = $o->fpassthru();
echo "END n=".$n."\n";
@unlink($p);
