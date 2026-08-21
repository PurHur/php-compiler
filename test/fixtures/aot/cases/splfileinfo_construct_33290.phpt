--TEST--
AOT: SplFileInfo __construct initialises path/filename (#33290)
--FILE--
<?php
$f = new SplFileInfo('test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
echo 'path=', $f->getPath(), "\n";
echo 'fn=', $f->getFilename(), "\n";
echo 'pn=', $f->getPathname(), "\n";
$abs = new SplFileInfo('/etc/passwd');
echo 'abs_path=', $abs->getPath(), "\n";
echo 'abs_fn=', $abs->getFilename(), "\n";
$seg = new SplFileInfo('/etc');
echo 'seg_path=', json_encode($seg->getPath()), "\n";
echo 'seg_fn=', json_encode($seg->getFilename()), "\n";
--EXPECT--
path=test/fixtures/aot/cases/directoryiterator_27289_fixture
fn=a.txt
pn=test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt
abs_path=/etc
abs_fn=passwd
seg_path=""
seg_fn="\/etc"
