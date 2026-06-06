/*
 * gzcompress/gzuncompress/gzdeflate/gzinflate/gzencode/gzdecode for AOT/JIT (issue #3194).
 * Reference: php/php-src ext/zlib/zlib.c (php_zlib_encode / php_zlib_decode).
 * Thin libz wrapper — PHP lowering in ext/standard/JitZlib.php.
 */

#include <stdint.h>
#include <stdlib.h>
#include <string.h>

#include <zlib.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

#define PHP_ZLIB_ENCODING_RAW 65534
#define PHP_ZLIB_ENCODING_DEFLATE 65535
#define PHP_ZLIB_ENCODING_GZIP 16

static size_t zc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const unsigned char *zc_strdata(__string__ *s)
{
    if (NULL == s) {
        return (const unsigned char *) "";
    }

    return (const unsigned char *) s + sizeof(void *) + sizeof(long long);
}

static __string__ *zc_string_from_bytes(const unsigned char *data, size_t len)
{
    if (NULL == data) {
        return NULL;
    }

    return __string__init((long long) len, (const char *) data);
}

static int zc_normalize_level(long long level)
{
    if (level < -1) {
        return Z_DEFAULT_COMPRESSION;
    }
    if (level > 9) {
        return 9;
    }

    return (int) level;
}

static int zc_is_gzip_encoding(long long encoding)
{
    return encoding == PHP_ZLIB_ENCODING_GZIP || encoding == 31;
}

static int zc_is_raw_encoding(long long encoding)
{
    return encoding == PHP_ZLIB_ENCODING_RAW || encoding == -15;
}

static int zc_is_deflate_encoding(long long encoding)
{
    return encoding == PHP_ZLIB_ENCODING_DEFLATE || encoding == -16;
}

static __string__ *zc_deflate_bytes(
    const unsigned char *in,
    size_t in_len,
    int level,
    int window_bits
)
{
    z_stream strm;
    unsigned char *out = NULL;
    size_t out_cap;
    size_t out_len = 0;
    int status;

    memset(&strm, 0, sizeof(strm));
    if (deflateInit2(&strm, level, Z_DEFLATED, window_bits, 8, Z_DEFAULT_STRATEGY) != Z_OK) {
        return NULL;
    }

    out_cap = deflateBound(&strm, in_len);
    if (out_cap < 64) {
        out_cap = 64;
    }
    out = (unsigned char *) malloc(out_cap);
    if (NULL == out) {
        deflateEnd(&strm);

        return NULL;
    }

    strm.next_in = (Bytef *) in;
    strm.avail_in = (uInt) in_len;
    strm.next_out = out;
    strm.avail_out = (uInt) out_cap;

    status = deflate(&strm, Z_FINISH);
    out_len = out_cap - strm.avail_out;
    deflateEnd(&strm);

    if (status != Z_STREAM_END) {
        free(out);

        return NULL;
    }

    return zc_string_from_bytes(out, out_len);
}

static __string__ *zc_inflate_bytes(
    const unsigned char *in,
    size_t in_len,
    int window_bits,
    long long max_length
)
{
    z_stream strm;
    unsigned char *out = NULL;
    size_t out_cap;
    size_t out_len = 0;
    int status;

    memset(&strm, 0, sizeof(strm));
    if (inflateInit2(&strm, window_bits) != Z_OK) {
        return NULL;
    }

    out_cap = in_len < 64 ? 64 : in_len * 4;
    if (max_length > 0 && (size_t) max_length < out_cap) {
        out_cap = (size_t) max_length;
    }
    out = (unsigned char *) malloc(out_cap);
    if (NULL == out) {
        inflateEnd(&strm);

        return NULL;
    }

    strm.next_in = (Bytef *) in;
    strm.avail_in = (uInt) in_len;
    strm.next_out = out;
    strm.avail_out = (uInt) out_cap;

    status = inflate(&strm, Z_FINISH);
    out_len = out_cap - strm.avail_out;
    inflateEnd(&strm);

    if (status != Z_STREAM_END && status != Z_OK) {
        free(out);

        return NULL;
    }
    if (max_length > 0 && out_len > (size_t) max_length) {
        free(out);

        return NULL;
    }

    return zc_string_from_bytes(out, out_len);
}

__string__ *__compiler_gzcompress(__string__ *data, long long level, long long encoding)
{
    const unsigned char *in;
    size_t in_len;
    uLongf out_len;
    unsigned char *out;
    int lvl;
    int rc;

    if (NULL == data) {
        return NULL;
    }
    in = zc_strdata(data);
    in_len = zc_strlen(data);
    lvl = zc_normalize_level(level);

    if (zc_is_deflate_encoding(encoding) || (!zc_is_raw_encoding(encoding) && !zc_is_gzip_encoding(encoding))) {
        out_len = compressBound((uLong) in_len);
        out = (unsigned char *) malloc(out_len);
        if (NULL == out) {
            return NULL;
        }
        rc = compress2(out, &out_len, in, (uLong) in_len, lvl);
        if (Z_OK != rc) {
            free(out);

            return NULL;
        }

        return zc_string_from_bytes(out, (size_t) out_len);
    }
    if (zc_is_gzip_encoding(encoding)) {
        return zc_deflate_bytes(in, in_len, lvl, 15 + 16);
    }

    return zc_deflate_bytes(in, in_len, lvl, -15);
}

__string__ *__compiler_gzuncompress(__string__ *data, long long max_length)
{
    const unsigned char *in;
    size_t in_len;
    uLongf out_len;
    unsigned char *out;
    int rc;

    if (NULL == data) {
        return NULL;
    }
    in = zc_strdata(data);
    in_len = zc_strlen(data);
    out_len = in_len < 64 ? 64 : (uLongf) (in_len * 4);
    if (max_length > 0 && (uLongf) max_length < out_len) {
        out_len = (uLongf) max_length;
    }
    out = (unsigned char *) malloc(out_len);
    if (NULL == out) {
        return NULL;
    }
    rc = uncompress(out, &out_len, in, (uLong) in_len);
    if (Z_OK != rc) {
        free(out);

        return NULL;
    }
    if (max_length > 0 && out_len > (uLongf) max_length) {
        free(out);

        return NULL;
    }

    return zc_string_from_bytes(out, (size_t) out_len);
}

__string__ *__compiler_gzdeflate(__string__ *data, long long level, long long encoding)
{
    const unsigned char *in;
    size_t in_len;
    int lvl;
    int window_bits = -15;

    if (NULL == data) {
        return NULL;
    }
    in = zc_strdata(data);
    in_len = zc_strlen(data);
    lvl = zc_normalize_level(level);

    if (zc_is_gzip_encoding(encoding)) {
        window_bits = 15 + 16;
    } else if (zc_is_deflate_encoding(encoding)) {
        window_bits = 15;
    } else if (zc_is_raw_encoding(encoding)) {
        window_bits = -15;
    }

    return zc_deflate_bytes(in, in_len, lvl, window_bits);
}

__string__ *__compiler_gzinflate(__string__ *data, long long max_length)
{
    if (NULL == data) {
        return NULL;
    }

    return zc_inflate_bytes(zc_strdata(data), zc_strlen(data), -15, max_length);
}

__string__ *__compiler_gzencode(__string__ *data, long long level, long long encoding)
{
    const unsigned char *in;
    size_t in_len;
    int lvl;
    int window_bits = 15 + 16;

    if (NULL == data) {
        return NULL;
    }
    in = zc_strdata(data);
    in_len = zc_strlen(data);
    lvl = zc_normalize_level(level);

    if (zc_is_raw_encoding(encoding)) {
        window_bits = -15;
    } else if (zc_is_deflate_encoding(encoding)) {
        window_bits = 15;
    } else if (zc_is_gzip_encoding(encoding)) {
        window_bits = 15 + 16;
    }

    return zc_deflate_bytes(in, in_len, lvl, window_bits);
}

__string__ *__compiler_gzdecode(__string__ *data, long long max_length)
{
    if (NULL == data) {
        return NULL;
    }

    return zc_inflate_bytes(zc_strdata(data), zc_strlen(data), 15 + 16, max_length);
}
