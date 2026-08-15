--TEST--
DOMCharacterData mutators excess argc → ArgumentCountError JIT (#31091)
--RUNFILE--
../../../repro/maintainer_gap_dom_characterdata_excess_argc.php
--EXPECT--
DOMCharacterData::substringData() expects exactly 2 arguments, 3 given
DOMCharacterData::appendData() expects exactly 1 argument, 2 given
DOMCharacterData::deleteData() expects exactly 2 arguments, 3 given
DOMCharacterData::insertData() expects exactly 2 arguments, 3 given
DOMCharacterData::replaceData() expects exactly 3 arguments, 4 given
hello
he
hello!
[Ello!
