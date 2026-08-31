<?php
/**
 * Inherited promoted ctor defaults must not apply when subclass defines __construct
 * without parent::__construct (Zend/zend_objects.c — parent ctor body not run).
 *
 * Parent promoted $dt/$n stay uninitialized; only child-promoted $tag is set.
 */
class ParentPromotedDefer {
    public function __construct(public DateTime $dt = new DateTime('2021-06-15'), public int $n = 5) {}
}
class ChildPromotedDefer extends ParentPromotedDefer {
    public function __construct(public string $tag = 'child') {}
}
$c = new ChildPromotedDefer();
try {
    echo $c->dt->format('Y-m-d'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo $c->tag, "\n";
