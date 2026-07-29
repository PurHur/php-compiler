<?php
/**
 * Issue #24971 — dirname/http_build_query/chunk_split/umask/touch Reflection defaults
 * (ext/standard stubs vs Zend).
 */
$funcs = [
    'dirname',
    'basename',
    'http_build_query',
    'chunk_split',
    'umask',
    'touch',
    'get_html_translation_table',
    'version_compare',
    'getimagesize',
    'session_set_cookie_params',
];
foreach ($funcs as $f) {
    $r = new ReflectionFunction($f);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $d = $p->isDefaultValueAvailable()
            ? var_export($p->getDefaultValue(), true)
            : ($p->isOptional() ? 'OPT' : 'REQ');
        $parts[] = $p->getName().'='.$d;
    }
    echo $f, '(', implode(', ', $parts), ')', PHP_EOL;
}

echo 'dirname=', dirname('/a/b/c'), PHP_EOL;
echo 'basename=', basename('/a/b/c.txt'), PHP_EOL;
echo 'hbq=', http_build_query(['a' => 1, 0 => 2]), PHP_EOL;
echo 'chunk=', var_export(chunk_split('abcd', 2), true), PHP_EOL;
echo 'vc=', var_export(version_compare('1.0', '1.0'), true), PHP_EOL;
echo 'html_tbl=', (int) is_array(get_html_translation_table()), PHP_EOL;
