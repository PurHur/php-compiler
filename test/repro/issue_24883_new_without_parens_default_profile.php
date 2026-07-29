<?php
// Issue #24883 — new Class()->method() must parse-error on default / PROFILE=8.2 (Zend 8.2).
// Enable with PHP_COMPILER_PROFILE=8.4.
echo new DateTimeImmutable('2020-01-01')->format('Y'), PHP_EOL;
