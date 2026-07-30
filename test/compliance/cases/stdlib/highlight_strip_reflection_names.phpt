--TEST--
stdlib highlight_file/show_source/php_strip_whitespace Reflection names (#23785)
--FILE--
<?php
foreach (['highlight_string', 'highlight_file', 'php_strip_whitespace', 'show_source'] as $f) {
    $r = new ReflectionFunction($f);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $d = $p->isDefaultValueAvailable()
            ? '=' . json_encode($p->getDefaultValue())
            : ($p->isOptional() ? '=?' : '');
        $parts[] = $p->getName() . $d;
    }
    echo $f . ': ' . implode(', ', $parts) . "\n";
}
$sample = '/compiler/test/compliance/cases/stdlib/highlight_run.php';
echo 'hs=' . (is_string(highlight_string(string: '<?php echo 1;', return: true)) ? 'ok' : 'bad') . "\n";
echo 'hf=' . (is_string(highlight_file(filename: $sample, return: true)) ? 'ok' : 'bad') . "\n";
echo 'psw=' . (is_string(php_strip_whitespace(filename: $sample)) ? 'ok' : 'bad') . "\n";
echo 'ss=' . (is_string(show_source(filename: $sample, return: true)) ? 'ok' : 'bad') . "\n";
echo 'old=' . (function () use ($sample) {
    try {
        highlight_file(file_name: $sample, return: true);
        return 'accepted';
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'file_name') ? 'rejected' : 'other';
    }
})() . "\n";
--EXPECT--
highlight_string: string, return=false
highlight_file: filename, return=false
php_strip_whitespace: filename
show_source: filename, return=false
hs=ok
hf=ok
psw=ok
ss=ok
old=rejected
