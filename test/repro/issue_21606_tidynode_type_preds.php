<?php
foreach (['isHtml', 'isJste', 'isAsp', 'isPhp'] as $m) {
    echo $m, '=', (int) method_exists('tidyNode', $m), "\n";
}
$t = @tidy_parse_string('<html><body><p>hi</p></body></html>');
if (false === $t) {
    echo "host=0\n";
    exit(0);
}
echo "host=1\n";
@tidy_clean_repair($t);
$body = tidy_get_body($t);
if (null !== $body) {
    echo 'body_isHtml=', (int) $body->isHtml(), "\n";
    echo 'body_isText=', (int) $body->isText(), "\n";
}
