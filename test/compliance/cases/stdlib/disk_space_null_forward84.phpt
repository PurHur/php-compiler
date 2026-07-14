--TEST--
stdlib disk_free_space()/disk_total_space()/diskfreespace(null) TypeError on 8.4 forward profile (#18994)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'disk_free_space' => static fn () => disk_free_space(null),
    'disk_total_space' => static fn () => disk_total_space(null),
    'diskfreespace' => static fn () => diskfreespace(null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
disk_free_space: disk_free_space(): Argument #1 ($directory) must be of type string, null given
disk_total_space: disk_total_space(): Argument #1 ($directory) must be of type string, null given
diskfreespace: diskfreespace(): Argument #1 ($directory) must be of type string, null given
