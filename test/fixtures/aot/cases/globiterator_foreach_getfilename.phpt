--TEST--
AOT: GlobIterator foreach getFilename (#27422)
--FILE--
<?php
$g = new GlobIterator('/etc/hosts');
foreach ($g as $f) {
    echo $f->getFilename(), "\n";
}
echo 'count=', $g->count(), "\n";
--EXPECT--
hosts
count=1
