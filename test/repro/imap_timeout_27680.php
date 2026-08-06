<?php
declare(strict_types=1);

echo function_exists('imap_timeout') ? 'Y' : 'N', PHP_EOL;
echo defined('IMAP_OPENTIMEOUT') ? 'Y' : 'N', PHP_EOL;
echo IMAP_OPENTIMEOUT, ',', IMAP_READTIMEOUT, ',', IMAP_WRITETIMEOUT, ',', IMAP_CLOSETIMEOUT, PHP_EOL;
var_export(imap_timeout(IMAP_OPENTIMEOUT));
echo PHP_EOL;
var_export(imap_timeout(IMAP_OPENTIMEOUT, 42));
echo PHP_EOL;
var_export(imap_timeout(IMAP_OPENTIMEOUT));
echo PHP_EOL;
$rf = new ReflectionFunction('imap_timeout');
echo (string) $rf->getReturnType(), PHP_EOL;
echo $rf->getParameters()[1]->getDefaultValue(), PHP_EOL;
echo $rf->getParameters()[0]->getName(), '/', $rf->getParameters()[1]->getName(), PHP_EOL;
