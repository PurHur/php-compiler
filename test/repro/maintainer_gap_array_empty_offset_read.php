<?php
/** Issue #12303 — empty [] offset in read context (Zend/zend_language_parser.y). */
$a = [1];
var_dump($a[]);
