<?php
/**
 * Repro for #24874: PROFILE=8.4 emits PHP 8.3+ highlight wire format.
 * Cases: highlight_string_pre_wrapper, highlight_file_br, highlight_file_multiline_br.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24874_highlight_string_wire.php
 */

$errors = 0;

$html = highlight_string('<?php echo 1;', true);
$expected = '<pre><code style="color: #000000"><span style="color: #0000BB">&lt;?php </span><span style="color: #007700">echo </span><span style="color: #0000BB">1</span><span style="color: #007700">;</span></code></pre>';
if ($html !== $expected) {
    echo "FAIL pre_wrapper byte_match\n";
    $errors++;
} else {
    echo "OK pre_wrapper byte_match\n";
}
if (str_contains($html, '&nbsp;')) {
    echo "FAIL: unexpected &nbsp;\n";
    $errors++;
} else {
    echo "OK: no &nbsp;\n";
}
if (!str_contains($html, '<pre>')) {
    echo "FAIL: missing <pre>\n";
    $errors++;
} else {
    echo "OK: has <pre>\n";
}

$f = tempnam(sys_get_temp_dir(), 'hl');
file_put_contents($f, "line1\nline2\n");
$fileHtml = highlight_file($f, true);
unlink($f);
if (substr_count($fileHtml, '<br') !== 0) {
    echo "FAIL: highlight_file should not use <br under 8.4\n";
    $errors++;
} else {
    echo "OK: highlight_file raw newlines\n";
}
if (!str_contains($fileHtml, '<pre>')) {
    echo "FAIL: highlight_file missing <pre>\n";
    $errors++;
} else {
    echo "OK: highlight_file <pre>\n";
}

exit($errors > 0 ? 1 : 0);
