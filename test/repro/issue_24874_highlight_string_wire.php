<?php
/**
 * Repro for #24874: default profile must emit PHP 8.3+ highlight wire format.
 *
 * Cases: highlight_string_pre_wrapper, highlight_file_br, highlight_file_multiline_br.
 */

$errors = 0;

$html = highlight_string('<?php echo 1;', true);
$expected = '<pre><code style="color: #000000"><span style="color: #0000BB">&lt;?php </span><span style="color: #007700">echo </span><span style="color: #0000BB">1</span><span style="color: #007700">;</span></code></pre>';
if ($html !== $expected) {
    echo "FAIL pre_wrapper byte_match\n";
    echo "got: ".$html."\n";
    $errors++;
} else {
    echo "OK pre_wrapper byte_match\n";
}
if (str_contains($html, '&nbsp;')) {
    echo "FAIL nbsp present\n";
    $errors++;
} else {
    echo "OK nbsp absent\n";
}

$f = tempnam(sys_get_temp_dir(), 'hl');
file_put_contents($f, "line1\nline2\n");
$fileHtml = highlight_file($f, true);
unlink($f);
if (substr_count((string) $fileHtml, '<br') !== 0) {
    echo "FAIL file_br count=".substr_count((string) $fileHtml, '<br')."\n";
    $errors++;
} else {
    echo "OK file_br\n";
}
if (strpos((string) $fileHtml, "line1\nline2") === false) {
    echo "FAIL raw newlines\n";
    $errors++;
} else {
    echo "OK raw newlines\n";
}
if (strpos((string) $fileHtml, '<pre>') === false) {
    echo "FAIL pre wrapper\n";
    $errors++;
} else {
    echo "OK pre wrapper\n";
}

exit($errors > 0 ? 1 : 0);
