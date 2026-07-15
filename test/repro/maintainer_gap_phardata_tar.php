<?php

declare(strict_types=1);

/**
 * #6490 — PharData .tar create + extractTo + PharFileInfo::getContent.
 */

echo class_exists('PharData', false) ? "class_ok\n" : "class_bad\n";

$base = sys_get_temp_dir().'/phardata_'.getmypid();
@mkdir($base);
$tar = $base.'/demo.tar';
@unlink($tar);

$p = new PharData($tar);
$p->addFromString('a.txt', 'hello');
echo file_exists($tar) ? "tar_written\n" : "tar_missing\n";
echo $p['a.txt']->getContent(), "\n";

$out = $base.'/out';
@mkdir($out);
echo $p->extractTo($out) ? "extract_ok\n" : "extract_bad\n";
echo is_file($out.'/a.txt') ? file_get_contents($out.'/a.txt')."\n" : "extracted_missing\n";

echo "ok\n";
