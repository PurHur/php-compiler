<?php
declare(strict_types=1);

$z = highlight_string('<?php echo 1;', true);
echo preg_match("/#000000\">\n<span/", $z) ? "nl_after_open\n" : "no_nl_after_open\n";
echo preg_match("/<\/span>\n<\/span>\n<\/code>/", $z) ? "nl_before_close\n" : "no_nl_before_close\n";

$f = tempnam(sys_get_temp_dir(), 'hl');
file_put_contents($f, '<?php echo 1;');
$fileHtml = highlight_file($f, true);
$showHtml = show_source($f, true);
unlink($f);
echo preg_match("/#000000\">\n<span/", $fileHtml) ? "file_nl_after_open\n" : "file_no_nl_after_open\n";
echo preg_match("/<\/span>\n<\/span>\n<\/code>/", $showHtml) ? "show_nl_before_close\n" : "show_no_nl_before_close\n";
