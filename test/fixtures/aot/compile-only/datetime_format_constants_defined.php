<?php
// AOT compile-only (#22271): DateTime/DateTimeImmutable format constants on class entries.
echo defined('DateTime::ATOM') ? '1' : '0', "\n";
echo defined('DateTimeImmutable::RFC3339') ? '1' : '0', "\n";
echo DateTime::W3C, "\n";
