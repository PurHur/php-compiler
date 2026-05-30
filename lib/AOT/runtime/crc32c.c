/*
 * crc32c() runtime for AOT/JIT (issue #3270).
 * CRC32C (Castagnoli), signed 32-bit return.
 * php-src: ext/standard/hash_crc32.c — polynomial 0x82F63B78 (reflected).
 */

#include <stdint.h>
#include <stdlib.h>

typedef struct __string__ __string__;

static size_t crc32c_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *crc32c_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static uint32_t crc32c_table[256];
static int crc32c_table_ready;

static void crc32c_init_table(void)
{
    if (crc32c_table_ready) {
        return;
    }
    for (uint32_t i = 0; i < 256; i++) {
        uint32_t c = i;
        for (int j = 0; j < 8; j++) {
            if (c & 1U) {
                c = 0x82F63B78U ^ (c >> 1);
            } else {
                c >>= 1;
            }
        }
        crc32c_table[i] = c;
    }
    crc32c_table_ready = 1;
}

static uint32_t crc32c_compute_bytes(const unsigned char *data, size_t len)
{
    crc32c_init_table();
    uint32_t crc = 0xFFFFFFFFU;
    for (size_t i = 0; i < len; i++) {
        crc = (crc >> 8) ^ crc32c_table[(crc ^ data[i]) & 0xFFU];
    }

    return crc ^ 0xFFFFFFFFU;
}

int64_t __compiler_crc32c(__string__ *subject)
{
    size_t len = crc32c_strlen(subject);
    const unsigned char *data = (const unsigned char *) crc32c_strdata(subject);
    uint32_t crc = crc32c_compute_bytes(data, len);

    return (int64_t) crc;
}
