--TEST--
AOT: SplFileInfo::getFileInfo/getPathInfo allocate SplFileInfo (#33298)
--FILE--
<?php
$f = new SplFileInfo('test/fixtures/aot/cases/directoryiterator_27289_fixture/a.txt');
$gi = $f->getFileInfo();
echo 'fi_class=', get_class($gi), "\n";
echo 'fi_name=', $gi->getFilename(), "\n";
echo 'fi_path=', $gi->getPath(), "\n";
$pi = $f->getPathInfo();
echo 'pi_class=', get_class($pi), "\n";
echo 'pi_name=', $pi->getFilename(), "\n";
--EXPECT--
fi_class=SplFileInfo
fi_name=a.txt
fi_path=test/fixtures/aot/cases/directoryiterator_27289_fixture
pi_class=SplFileInfo
pi_name=directoryiterator_27289_fixture
