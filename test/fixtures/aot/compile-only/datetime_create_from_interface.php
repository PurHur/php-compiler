<?php
// Compile-only (#5936): DateTime::createFromInterface / DateTimeImmutable::createFromInterface VM builtins.
$immutable = new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC'));
$mutable = new DateTime('2020-01-01', new DateTimeZone('UTC'));
$copy = DateTime::createFromInterface($immutable);
$icopy = DateTimeImmutable::createFromInterface($mutable);
var_export($copy->format('Y-m-d'));
var_export($icopy->format('Y-m-d'));
