--TEST--
Language: enum use Trait before trait definition — forward ref binds (#9395, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'a';
    use T;
    public function hi(): string { return 'hi'; }
}

trait T {}

var_dump(E::A->hi());

trait First { public function ok(): string { return 'ok'; } }
enum F: int {
    case B = 1;
    use First;
}
var_dump(F::B->ok());
--EXPECT--
string(2) "hi"
string(2) "ok"
