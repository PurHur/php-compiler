--TEST--
Language: enum use Trait — trait methods merged onto enum case objects (#6623, #5709, #8808)
--FILE--
<?php
declare(strict_types=1);

trait T {
    public function f(): string { return 'ok'; }
}

enum E: string {
    case A = 'a';
    use T;
}

echo E::A->f(), "\n";
--EXPECT--
ok
