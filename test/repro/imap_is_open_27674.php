<?php
/**
 * Issue #27674 — imap_is_open() PHP 8.3+.
 *
 * Run: PHP_COMPILER_ENABLE_IMAP=1 PHP_COMPILER_PROFILE=8.3 php bin/vm.php test/repro/imap_is_open_27674.php
 */
declare(strict_types=1);

echo function_exists('imap_is_open') ? 'Y' : 'N', PHP_EOL;

$rf = new ReflectionFunction('imap_is_open');
echo $rf->getParameters()[0]->getName(), PHP_EOL;
echo $rf->hasReturnType() ? (string) $rf->getReturnType() : '-', PHP_EOL;
echo $rf->getParameters()[0]->hasType() ? (string) $rf->getParameters()[0]->getType() : '-', PHP_EOL;

$fixture = __DIR__.'/../fixtures/imap/tiny.mbox';
$mbox = imap_open($fixture, '', '');
echo $mbox instanceof IMAP\Connection ? 'open' : 'no', PHP_EOL;
echo imap_is_open($mbox) ? 'live' : 'dead', PHP_EOL;
echo imap_is_open(imap: $mbox) ? 'named_live' : 'named_dead', PHP_EOL;
imap_close($mbox);
echo imap_is_open($mbox) ? 'still' : 'closed', PHP_EOL;
