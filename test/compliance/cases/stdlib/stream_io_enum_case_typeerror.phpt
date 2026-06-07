--TEST--
stdlib stream I/O — enum case stream operand TypeError (#6170, ext/standard/streams.c)
--FILE--
<?php
enum E: int { case A = 1; }

try {
    feof(E::A);
    echo "feof: uncaught\n";
} catch (TypeError $e) {
    echo "feof: ", $e->getMessage(), "\n";
}
try {
    fflush(E::A);
    echo "fflush: uncaught\n";
} catch (TypeError $e) {
    echo "fflush: ", $e->getMessage(), "\n";
}
try {
    fsync(E::A);
    echo "fsync: uncaught\n";
} catch (TypeError $e) {
    echo "fsync: ", $e->getMessage(), "\n";
}
try {
    fdatasync(E::A);
    echo "fdatasync: uncaught\n";
} catch (TypeError $e) {
    echo "fdatasync: ", $e->getMessage(), "\n";
}
try {
    flock(E::A, LOCK_EX);
    echo "flock: uncaught\n";
} catch (TypeError $e) {
    echo "flock: ", $e->getMessage(), "\n";
}
try {
    fseek(E::A, 0);
    echo "fseek: uncaught\n";
} catch (TypeError $e) {
    echo "fseek: ", $e->getMessage(), "\n";
}
try {
    ftell(E::A);
    echo "ftell: uncaught\n";
} catch (TypeError $e) {
    echo "ftell: ", $e->getMessage(), "\n";
}
try {
    rewind(E::A);
    echo "rewind: uncaught\n";
} catch (TypeError $e) {
    echo "rewind: ", $e->getMessage(), "\n";
}
try {
    ftruncate(E::A, 0);
    echo "ftruncate: uncaught\n";
} catch (TypeError $e) {
    echo "ftruncate: ", $e->getMessage(), "\n";
}
try {
    fclose(E::A);
    echo "fclose: uncaught\n";
} catch (TypeError $e) {
    echo "fclose: ", $e->getMessage(), "\n";
}
try {
    fread(E::A, 1);
    echo "fread: uncaught\n";
} catch (TypeError $e) {
    echo "fread: ", $e->getMessage(), "\n";
}
try {
    fwrite(E::A, 'x');
    echo "fwrite: uncaught\n";
} catch (TypeError $e) {
    echo "fwrite: ", $e->getMessage(), "\n";
}
--EXPECT--
feof: feof(): Argument #1 ($stream) must be of type resource, E given
fflush: fflush(): Argument #1 ($stream) must be of type resource, E given
fsync: fsync(): Argument #1 ($stream) must be of type resource, E given
fdatasync: fdatasync(): Argument #1 ($stream) must be of type resource, E given
flock: flock(): Argument #1 ($stream) must be of type resource, E given
fseek: fseek(): Argument #1 ($stream) must be of type resource, E given
ftell: ftell(): Argument #1 ($stream) must be of type resource, E given
rewind: rewind(): Argument #1 ($stream) must be of type resource, E given
ftruncate: ftruncate(): Argument #1 ($stream) must be of type resource, E given
fclose: fclose(): Argument #1 ($stream) must be of type resource, E given
fread: fread(): Argument #1 ($stream) must be of type resource, E given
fwrite: fwrite(): Argument #1 ($stream) must be of type resource, E given
