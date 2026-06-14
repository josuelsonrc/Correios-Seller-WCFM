<?php

declare(strict_types=1);

namespace CorreiosSeller\Support;

final class Cache
{
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cacheKey = $this->key($key);
        $cached = get_transient($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $value = $callback();
        set_transient($cacheKey, $value, max(60, $ttl));
        set_transient($cacheKey . '_last_good', $value, DAY_IN_SECONDS * 7);

        return $value;
    }

    public function lastGood(string $key): mixed
    {
        return get_transient($this->key($key) . '_last_good');
    }

    private function key(string $key): string
    {
        return 'correios_seller_' . md5($key);
    }
}
