--TEST--
Late static binding: static::factory() uses called class (issue #1858)
--FILE--
<?php
class Factory {
    public static function make(): string {
        return static::class;
    }
}
class SubFactory extends Factory {
    public function probe(): void {
        echo static::make(), "\n";
    }
}
echo Factory::make(), "\n";
echo SubFactory::make(), "\n";
$f = new SubFactory();
$f->probe();
--EXPECT--
Factory
SubFactory
SubFactory
