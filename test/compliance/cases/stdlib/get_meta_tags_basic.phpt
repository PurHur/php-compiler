--TEST--
stdlib get_meta_tags() — name/content from HTML file (#3703)
--FILE--
<?php
echo function_exists('get_meta_tags') ? "fn\n" : "no-fn\n";
$path = 'test/compliance/cases/stdlib/get_meta_tags_fixture.html';
$tags = get_meta_tags($path, true);
echo isset($tags['author']) && $tags['author'] === 'me' ? "author\n" : "bad-author\n";
echo @get_meta_tags('/nonexistent/phpc_meta_tags_missing.html', true) === false ? "missing\n" : "found\n";
--EXPECT--
fn
author
missing
