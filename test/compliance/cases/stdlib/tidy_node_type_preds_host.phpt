--TEST--
tidyNode isHtml/isJste/isAsp/isPhp host soft path (#21606)
--FILE--
<?php
declare(strict_types=1);
$t = @tidy_parse_string('<html><body><p>hi</p></body></html>');
if (false === $t) {
    echo "tidy_live=0\n";
    echo "ok\n";
    exit(0);
}
echo "tidy_live=1\n";
@tidy_clean_repair($t);
$body = @tidy_get_body($t);
if (null === $body) {
    echo "body=null\n";
    echo "ok\n";
    exit(0);
}
echo 'is_html=', (int) $body->isHtml(), "\n";
echo 'is_text=', (int) $body->isText(), "\n";
echo 'is_jste=', (int) $body->isJste(), "\n";
echo 'is_asp=', (int) $body->isAsp(), "\n";
echo 'is_php=', (int) $body->isPhp(), "\n";
echo "ok\n";
?>
--EXPECTF--
tidy_live=%d
%a
