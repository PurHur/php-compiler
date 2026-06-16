--TEST--
Language: enum instance methods dispatch on case objects (#9023, Zend/zend_enum.c)
--FILE--
<?php
enum E: string {
    case A = 'a';
    public function label(): string { return $this->name; }
}
echo E::A->label(), "\n";
--EXPECT--
A

