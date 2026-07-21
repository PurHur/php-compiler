<?php
/**
 * Issue #21691 — PharData archive metadata get/set/has/del (php-src zim_Phar_*Metadata).
 */
declare(strict_types=1);

@unlink('/tmp/pmeta_21691.tar');
$p = new PharData('/tmp/pmeta_21691.tar');
$p['a.txt'] = 'hi';
$p->setMetadata(['k' => 1]);
var_export($p->getMetadata());
echo "\n";
echo $p->hasMetadata() ? 'y' : 'n', "\n";
$p->delMetadata();
echo $p->hasMetadata() ? 'y' : 'n', "\n";

unset($p);
$p2 = new PharData('/tmp/pmeta_21691.tar');
echo $p2->hasMetadata() ? 'y' : 'n', "\n";
var_export($p2->getMetadata());
echo "\n";
