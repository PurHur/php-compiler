--TEST--
stdlib highlight_string/file/show_source — reference Zend 8.2 <code><span> + &nbsp; (#25063, ext/standard)
--FILE--
<?php
$z = highlight_string('<?php echo 1;', true);
echo 'nbsp=', (strpos($z, '&nbsp;') !== false ? '1' : '0'), "\n";
echo 'pre=', (strpos($z, '<pre>') !== false ? '1' : '0'), "\n";
echo 'code_span=', (preg_match('/<code><span/', $z) ? '1' : '0'), "\n";

$f = tempnam(sys_get_temp_dir(), 'hl');
file_put_contents($f, '<?php echo 1;');
$fileHtml = highlight_file($f, true);
$showHtml = show_source($f, true);
unlink($f);
echo 'file_nbsp=', (strpos($fileHtml, '&nbsp;') !== false ? '1' : '0'), "\n";
echo 'file_pre=', (strpos($fileHtml, '<pre>') !== false ? '1' : '0'), "\n";
echo 'show_code_span=', (preg_match('/<code><span/', $showHtml) ? '1' : '0'), "\n";
?>
--EXPECT--
nbsp=1
pre=0
code_span=1
file_nbsp=1
file_pre=0
show_code_span=1
