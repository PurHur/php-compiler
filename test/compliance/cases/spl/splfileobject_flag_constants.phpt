--TEST--
SPL SplFileObject::READ_AHEAD/SKIP_EMPTY/DROP_NEW_LINE/READ_CSV constants (#14576, ext/spl/spl_file_object.c)
--FILE--
<?php
echo SplFileObject::READ_AHEAD, ':', SplFileObject::SKIP_EMPTY, ':', SplFileObject::DROP_NEW_LINE, ':', SplFileObject::READ_CSV, "\n";
$fo = new SplTempFileObject();
$flags = SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY;
$fo->setFlags($flags);
echo $fo->getFlags(), "\n";
--EXPECT--
2:4:1:8
6
