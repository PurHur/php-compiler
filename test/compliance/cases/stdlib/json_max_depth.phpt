--TEST--
stdlib json_encode()/json_decode() default max depth 512 (#11637, ext/json/php_json.c)
--FILE--
<?php
function nest(int $depth): array
{
    return $depth <= 0 ? [] : [nest($depth - 1)];
}
function nestJson(int $depth): string
{
    return $depth <= 0 ? '[]' : '['.nestJson($depth - 1).']';
}
$encFail = json_encode(nest(512));
echo ($encFail === false && json_last_error() === 1 ? 'enc512depth' : 'enc512bad'), "\n";
json_decode(nestJson(510), true);
echo (json_last_error() === 0 ? 'dec510' : 'dec510bad'), "\n";
json_decode(nestJson(511), true);
echo (json_last_error() === 1 ? 'dec511depth' : 'dec511bad'), "\n";
--EXPECT--
enc512depth
dec510
dec511depth
