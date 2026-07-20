--TEST--
tidy_get_opt_doc / getOptDoc host soft path (#21604)
--FILE--
<?php
declare(strict_types=1);
$t = @tidy_parse_string('<p>x</p>');
if (false === $t) {
    echo "tidy_live=0\n";
    echo "ok\n";
    exit(0);
}
echo "tidy_live=1\n";
$doc = @tidy_get_opt_doc($t, 'indent');
echo 'doc_type=', gettype($doc), "\n";
if (is_string($doc)) {
    echo 'doc_len=', strlen($doc) > 0 ? 1 : 0, "\n";
} else {
    echo 'doc_false=', (int) ($doc === false), "\n";
}
$mdoc = @$t->getOptDoc('indent');
echo 'm_doc_type=', gettype($mdoc), "\n";
echo "ok\n";
?>
--EXPECTF--
tidy_live=%d
%a
