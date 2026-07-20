--TEST--
tidyNode + root/html/head/body host soft path (#21543)
--FILE--
<?php
declare(strict_types=1);
$t = @tidy_parse_string('<html><head><title>x</title></head><body><p>hi</p><!--c--></body></html>');
if (false === $t) {
    echo "tidy_live=0\n";
    echo "ok\n";
    exit(0);
}
echo "tidy_live=1\n";
@tidy_clean_repair($t);
$body = @tidy_get_body($t);
echo 'body_class=', (null === $body) ? 'null' : get_class($body), "\n";
if (null !== $body) {
    echo 'body_name=', $body->name, "\n";
    echo 'has_children=', (int) $body->hasChildren(), "\n";
    echo 'is_text=', (int) $body->isText(), "\n";
    $parent = $body->getParent();
    echo 'parent=', (null === $parent) ? 'null' : $parent->name, "\n";
}
$html = @$t->html();
echo 'm_html=', (null === $html) ? 'null' : $html->name, "\n";
$head = @tidy_get_head($t);
echo 'head=', (null === $head) ? 'null' : $head->name, "\n";
$root = @$t->root();
echo 'root=', (null === $root) ? 'null' : get_class($root), "\n";
echo "ok\n";
?>
--EXPECTF--
tidy_live=%d
%a
