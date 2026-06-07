--TEST--
Language: #[\AllowDynamicProperties] on readonly class compile-time fatal (#7299)
--FILE--
<?php
#[\AllowDynamicProperties]
readonly class R {
    public function __construct(public int $x) {}
}
echo "ok\n";
--EXPECT_EXIT--
255
