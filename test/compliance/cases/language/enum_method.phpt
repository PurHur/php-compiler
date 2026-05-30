--TEST--
Language: backed enum instance method dispatch (#3804, Zend zend_enum.c)
--FILE--
<?php
enum Status: string {
    case Ok = 'ok';
    public function label(): string {
        return $this->name;
    }
}
echo Status::Ok->label();
--EXPECT--
Ok
