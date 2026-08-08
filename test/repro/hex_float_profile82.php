<?php
// Issue #29061 — hex float literals are PHP 8.4+; Zend 8.2 / PROFILE=8.2 must parse-error.
echo 0x1.8p1;
