<?php
// Zend parity: ext/standard/string.c php_wordwrap() — full $break when cut=true (#3774).
echo wordwrap('abcdef', 3, '--', true), "\n";
