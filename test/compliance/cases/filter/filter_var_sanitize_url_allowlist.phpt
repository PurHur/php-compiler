--TEST--
stdlib filter_var() FILTER_SANITIZE_URL keeps <>()* (#29016, ext/filter/sanitizing_filters.c)
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'http://ex.com/a b<script>',
    'javascript:alert(1)',
    'http://ex.com/foo bar',
] as $u) {
    echo var_export(filter_var($u, FILTER_SANITIZE_URL), true), "\n";
}
--EXPECT--
'http://ex.com/ab<script>'
'javascript:alert(1)'
'http://ex.com/foobar'
