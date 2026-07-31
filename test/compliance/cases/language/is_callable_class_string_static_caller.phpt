--TEST--
language is_callable() class-string instance method false from static caller (#25873, Zend/zend_execute_API.c)
--FILE--
<?php
class IsCallableStaticCaller25873
{
    private function p() {}
    protected function q() {}
    public function pub() {}
    private static function s() {}

    public static function fromStatic(): void
    {
        echo 'static-priv=', (int) is_callable([self::class, 'p']), "\n";
        echo 'static-prot=', (int) is_callable([self::class, 'q']), "\n";
        echo 'static-pub=', (int) is_callable([self::class, 'pub']), "\n";
        echo 'static-str-priv=', (int) is_callable('IsCallableStaticCaller25873::p'), "\n";
        echo 'static-static-priv=', (int) is_callable([self::class, 's']), "\n";
        echo 'static-object-priv=', (int) is_callable([new self, 'p']), "\n";
    }

    public function fromInstance(): void
    {
        echo 'inst-priv=', (int) is_callable([self::class, 'p']), "\n";
        echo 'inst-pub=', (int) is_callable([self::class, 'pub']), "\n";
    }
}

IsCallableStaticCaller25873::fromStatic();
(new IsCallableStaticCaller25873)->fromInstance();
echo 'outside-pub=', (int) is_callable([IsCallableStaticCaller25873::class, 'pub']), "\n";
--EXPECT--
static-priv=0
static-prot=0
static-pub=0
static-str-priv=0
static-static-priv=1
static-object-priv=1
inst-priv=1
inst-pub=1
outside-pub=0
