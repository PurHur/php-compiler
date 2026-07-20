--TEST--
tidy release/html_ver/is_xhtml/is_xml host soft path (#21542)
--FILE--
<?php
declare(strict_types=1);
$rel = @tidy_get_release();
echo is_string($rel) ? 'release=ok' : 'release=bad', "\n";
$t = @tidy_parse_string('<title>x</title><p>hi');
if (false === $t) {
    echo "tidy_live=0\n";
    echo "ok\n";
    exit(0);
}
echo "tidy_live=1\n";
echo 'ver=', (int) tidy_get_html_ver($t), "\n";
echo 'xhtml=', (int) tidy_is_xhtml($t), "\n";
echo 'xml=', (int) tidy_is_xml($t), "\n";
echo 'm_rel=', is_string($t->getRelease()) ? 'ok' : 'bad', "\n";
echo 'm_ver=', (int) $t->getHtmlVer(), "\n";
echo 'm_xhtml=', (int) $t->isXhtml(), "\n";
echo 'm_xml=', (int) $t->isXml(), "\n";
echo "ok\n";
?>
--EXPECTF--
release=ok
tidy_live=%d
%a
