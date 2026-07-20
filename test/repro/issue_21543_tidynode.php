<?php
echo 'class=', (int) class_exists('tidyNode'), "\n";
echo 'fn_body=', (int) function_exists('tidy_get_body'), "\n";
echo 'fn_root=', (int) function_exists('tidy_get_root'), "\n";
echo 'm_body=', (int) method_exists('tidy', 'body'), "\n";
echo 'm_has=', (int) method_exists('tidyNode', 'hasChildren'), "\n";
$t = @tidy_parse_string('<html><head><title>x</title></head><body><p>hi</p></body></html>');
if (false === $t) {
    echo "host=0\n";
    exit(0);
}
echo "host=1\n";
@tidy_clean_repair($t);
$body = tidy_get_body($t);
echo 'body=', (null === $body) ? 'null' : get_class($body).':'.$body->name, "\n";
if (null !== $body) {
    echo 'hasChildren=', (int) $body->hasChildren(), "\n";
    echo 'isText=', (int) $body->isText(), "\n";
}
