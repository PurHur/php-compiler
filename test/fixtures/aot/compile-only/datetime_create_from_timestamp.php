<?php
// Compile-only (#5973): DateTime::createFromTimestamp / DateTimeImmutable::createFromTimestamp VM builtins.
date_default_timezone_set('UTC');
$dt = DateTime::createFromTimestamp(1700000000);
$di = DateTimeImmutable::createFromTimestamp(1700000000);
var_export($dt->getTimestamp());
var_export($di->getTimestamp());
