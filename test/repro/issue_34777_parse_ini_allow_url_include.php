<?php

declare(strict_types=1);

/**
 * #34777 — parse_ini_file() must honor allow_url_include for data:// (peer #32104).
 *
 * Zend default: wrapper-disabled Warning + false. file_get_contents(data://) still works.
 */
$uri = 'data://text/plain,a=1';
$b64 = 'data://text/plain;base64,YT0x'; // a=1

echo 'allow_url_include=', var_export(ini_get('allow_url_include'), true), "\n";
echo 'data=', var_export(parse_ini_file($uri), true), "\n";
echo 'base64=', var_export(parse_ini_file($b64), true), "\n";
echo 'fgc=', var_export(file_get_contents($uri), true), "\n";
