--TEST--
stdlib filter_var() FILTER_SANITIZE_URL allow-list JIT (#29016)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('javascript:alert(1)', FILTER_SANITIZE_URL));
echo "\n";
var_export(filter_var('http://ex.com/a b<script>', FILTER_SANITIZE_URL));
echo "\n";
--EXPECT--
'javascript:alert(1)'
'http://ex.com/ab<script>'
