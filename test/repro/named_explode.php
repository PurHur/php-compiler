<?php
// Issue #10034 — explode() separator:/string: named parameters (php-src ext/standard/string.c)
var_export(explode(separator: ',', string: 'a,b,c'));
