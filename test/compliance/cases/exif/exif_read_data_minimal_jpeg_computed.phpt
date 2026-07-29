--TEST--
exif exif_read_data() — baseline JPEG without APP1 returns COMPUTED (#24582, ext/exif/exif.c)
--FILE--
<?php
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGfAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAQUCf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Bf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Bf//Z');
$f = sys_get_temp_dir() . '/exif_min_compliance.jpg';
file_put_contents($f, $jpeg);
echo exif_imagetype($f) === IMAGETYPE_JPEG ? "imagetype_ok\n" : "imagetype_fail\n";
$data = @exif_read_data($f);
echo is_array($data) ? "array_ok\n" : "array_fail\n";
echo (isset($data['COMPUTED']['Width']) && isset($data['COMPUTED']['Height'])) ? "computed_ok\n" : "computed_fail\n";
echo ($data['COMPUTED']['Width'] ?? 0), "\n";
echo ($data['COMPUTED']['Height'] ?? 0), "\n";
echo ($data['SectionsFound'] ?? 'missing') === '' ? "sections_empty_ok\n" : "sections_empty_fail\n";
@unlink($f);
--EXPECT--
imagetype_ok
array_ok
computed_ok
1
1
sections_empty_ok
