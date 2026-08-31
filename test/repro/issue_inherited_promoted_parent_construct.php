<?php
/**
 * Inherited promoted ctor `new` default via parent::__construct() (#6652 leftover).
 * php-src: Zend/zend_compile.c default_value, zend_objects.c property init.
 */
class ParentDt {
    public function __construct(public DateTime $dt = new DateTime('2021-06-15')) {}
}
class ChildCallsParent extends ParentDt {
    public function __construct()
    {
        parent::__construct();
    }
}
echo (new ChildCallsParent)->dt->format('Y-m-d'), "\n";

class ChildCallsParentExplicit extends ParentDt {
    public function __construct()
    {
        parent::__construct(new DateTime('2022-03-04'));
    }
}
echo (new ChildCallsParentExplicit)->dt->format('Y-m-d'), "\n";

class ChildNoCall extends ParentDt {
    public function __construct() {}
}
try {
    echo (new ChildNoCall)->dt->format('Y-m-d'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
