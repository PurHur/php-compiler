--TEST--
Language: enum typed class const — getCases() excludes user consts (zend_compile.c, #7370)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
enum E: string {
    public const string LABEL = 'enum';
    case A = 'a';
}
echo E::LABEL, "\n";
$re = new ReflectionEnum(E::class);
foreach ($re->getCases() as $case) {
    echo $case->getName(), "\n";
}
--EXPECT--
enum
A
