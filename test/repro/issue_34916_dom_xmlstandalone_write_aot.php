<?php

// #34916 — AOT DOMDocument xmlStandalone / xmlVersion write+read must match Zend.
$d = new DOMDocument();
echo (int) $d->xmlStandalone, PHP_EOL;
echo $d->xmlVersion, PHP_EOL;
$d->xmlStandalone = true;
$d->standalone = true;
$d->xmlVersion = '1.1';
$d->version = '1.1';
echo (int) $d->xmlStandalone, PHP_EOL;
echo (int) $d->standalone, PHP_EOL;
echo $d->xmlVersion, PHP_EOL;
echo $d->version, PHP_EOL;
