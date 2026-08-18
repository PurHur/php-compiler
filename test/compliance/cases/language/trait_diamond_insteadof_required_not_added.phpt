--TEST--
Language: diamond trait insteadof — required trait not directly used (#32130, zend_inheritance.c)
--FILE--
<?php
trait DA {}
trait DB { use DA; }
trait DC { use DA; }
class DD {
    use DB, DC {
        DA::m insteadof DB, DC;
    }
}
echo "unreached\n";
--EXPECT_EXIT--
255
--EXPECT--
Required Trait DA wasn't added to DD
