--TEST--
stdlib highlight_string() — Zend 8.4 <pre><code> wrapper and literal spaces (#23733, ext/standard/highlight.c)
--FILE--
<?php
$html = highlight_string('<?php echo 1;', true);
$expected = '<pre><code style="color: #000000"><span style="color: #0000BB">&lt;?php </span><span style="color: #007700">echo </span><span style="color: #0000BB">1</span><span style="color: #007700">;</span></code></pre>';
echo $html === $expected ? "byte_match=ok\n" : "byte_match=fail\n";
echo str_contains($html, '&nbsp;') ? "nbsp=fail\n" : "nbsp=ok\n";
--EXPECT--
byte_match=ok
nbsp=ok
