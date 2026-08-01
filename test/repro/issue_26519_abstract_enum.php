<?php
// Repro #26519 — abstract enum must parse-fatal like Zend (zend_language_parser.y).
abstract enum E { case A; }
echo "ok\n";
