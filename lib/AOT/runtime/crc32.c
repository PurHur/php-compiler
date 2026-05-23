/*
 * crc32() runtime for AOT/JIT (issue #1014).
 * CRC32B (IEEE 802.3), signed 32-bit return.
 */

#include <stdint.h>
#include <stdlib.h>

typedef struct __string__ __string__;

static size_t crc32_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *crc32_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static uint32_t crc32_table[256];
static int crc32_table_ready;

static void crc32_init_table(void)
{
    if (crc32_table_ready) {
        return;
    }
    for (uint32_t i = 0; i < 256; i++) {
        uint32_t c = i;
        for (int j = 0; j < 8; j++) {
            if (c & 1U) {
                c = 0xEDB88320U ^ (c >> 1);
            } else {
                c >>= 1;
            }
        }
        crc32_table[i] = c;
    }
    crc32_table_ready = 1;
}

static uint32_t crc32_compute_bytes(uint32_t crc, const unsigned char *data, size_t len)
{
    crc32_init_table();
    crc ^= 0xFFFFFFFFU;
    for (size_t i = 0; i < len; i++) {
        crc = (crc >> 8) ^ crc32_table[(crc ^ data[i]) & 0xFFU];
    }

    return crc ^ 0xFFFFFFFFU;
}

int64_t __compiler_crc32(__string__ *subject, int64_t seed)
{
    size_t len = crc32_strlen(subject);
    const unsigned char *data = (const unsigned char *) crc32_strdata(subject);
    uint32_t crc = crc32_compute_bytes((uint32_t) seed, data, len);

    return (int64_t) crc;
}
