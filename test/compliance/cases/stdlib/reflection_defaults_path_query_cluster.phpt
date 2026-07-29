--TEST--
stdlib Reflection defaults dirname/http_build_query/chunk_split/umask/touch cluster (#24971)
--FILE--
<?php
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
?>
--EXPECT--
dirname(path=REQ, levels=1)
basename(path=REQ, suffix='')
http_build_query(data=REQ, numeric_prefix='', arg_separator=NULL, encoding_type=1)
chunk_split(string=REQ, length=76, separator='
')
umask(mask=NULL)
touch(filename=REQ, mtime=NULL, atime=NULL)
get_html_translation_table(table=0, flags=11, encoding='UTF-8')
version_compare(version1=REQ, version2=REQ, operator=NULL)
getimagesize(filename=REQ, image_info=NULL)
session_set_cookie_params(lifetime_or_options=REQ, path=NULL, domain=NULL, secure=NULL, httponly=NULL)
dirname=/a/b
basename=c.txt
hbq=a=1&0=2
chunk='ab
cd
'
vc=0
