<?php
class RedisCache {
    private static $redis = null;
    private static $cache_prefix = 'shms:';

    /**
     * Initialize Redis connection - Lazy loading with error handling
     */
    private static function initRedis() {
        if (self::$redis === null) {
            // Use the optimized connection function
            self::$redis = getRedisConnection();
        }
    }

    /**
     * Generate cache key
     */
    private static function getCacheKey($key) {
        return self::$cache_prefix . $key;
    }

    /**
     * Set cache with TTL
     */
    public static function set($key, $value, $ttl = 300) { // Default 5 minutes
        self::initRedis();
        if (self::$redis) {
            $cache_key = self::getCacheKey($key);
            $result = self::$redis->setex($cache_key, $ttl, serialize($value));
            return $result === true || ($result instanceof Predis\Response\Status && $result->getPayload() === 'OK');
        }
        return false;
    }

    /**
     * Get cached value
     */
    public static function get($key) {
        self::initRedis();
        if (self::$redis) {
            $cache_key = self::getCacheKey($key);
            $value = self::$redis->get($cache_key);
            return $value ? unserialize($value) : null;
        }
        return null;
    }

    /**
     * Delete cached value
     */
    public static function delete($key) {
        self::initRedis();
        if (self::$redis) {
            $cache_key = self::getCacheKey($key);
            $result = self::$redis->del($cache_key);
            return $result > 0;
        }
        return false;
    }

    /**
     * Check if key exists in cache
     */
    public static function exists($key) {
        self::initRedis();
        if (self::$redis) {
            $cache_key = self::getCacheKey($key);
            return self::$redis->exists($cache_key);
        }
        return false;
    }

    /**
     * Clear all cache with prefix
     */
    public static function clearAll() {
        self::initRedis();
        if (self::$redis) {
            $pattern = self::$cache_prefix . '*';
            $keys = self::$redis->keys($pattern);
            if (!empty($keys)) {
                $result = self::$redis->del($keys);
                return $result > 0;
            }
        }
        return false;
    }

    /**
     * Get cache statistics
     */
    public static function getStats() {
        self::initRedis();
        if (self::$redis) {
            $pattern = self::$cache_prefix . '*';
            $keys = self::$redis->keys($pattern);
            $total_keys = count($keys);

            $total_size = 0;
            foreach ($keys as $key) {
                $ttl = self::$redis->ttl($key);
                if ($ttl > 0) {
                    $total_size += strlen(self::$redis->get($key));
                }
            }

            return [
                'total_keys' => $total_keys,
                'total_size_bytes' => $total_size,
                'redis_connected' => true
            ];
        }
        return [
            'total_keys' => 0,
            'total_size_bytes' => 0,
            'redis_connected' => false
        ];
    }

    /**
     * Cache API response with smart key generation
     */
    public static function cacheApiResponse($endpoint, $params = [], $data, $ttl = 300) {
        $param_str = is_array($params) ? http_build_query($params) : $params;
        $cache_key = 'api:' . $endpoint . ':' . md5($param_str);
        return self::set($cache_key, $data, $ttl);
    }

    /**
     * Get cached API response
     */
    public static function getCachedApiResponse($endpoint, $params = []) {
        $param_str = is_array($params) ? http_build_query($params) : $params;
        $cache_key = 'api:' . $endpoint . ':' . md5($param_str);
        return self::get($cache_key);
    }

    /**
     * Cache dashboard statistics
     */
    public static function cacheDashboardStats($dashboard_type, $data, $ttl = 180) { // 3 minutes for stats
        $cache_key = 'dashboard:' . $dashboard_type;
        return self::set($cache_key, $data, $ttl);
    }

    /**
     * Get cached dashboard statistics
     */
    public static function getCachedDashboardStats($dashboard_type) {
        $cache_key = 'dashboard:' . $dashboard_type;
        return self::get($cache_key);
    }

    /**
     * Cache user session data
     */
    public static function cacheUserSession($user_id, $session_data, $ttl = 3600) { // 1 hour
        $cache_key = 'session:user:' . $user_id;
        return self::set($cache_key, $session_data, $ttl);
    }

    /**
     * Get cached user session data
     */
    public static function getCachedUserSession($user_id) {
        $cache_key = 'session:user:' . $user_id;
        return self::get($cache_key);
    }

    /**
     * Cache database query results
     */
    public static function cacheQueryResult($query_key, $data, $ttl = 600) { // 10 minutes
        $cache_key = 'query:' . md5($query_key);
        return self::set($cache_key, $data, $ttl);
    }

    /**
     * Get cached query result
     */
    public static function getCachedQueryResult($query_key) {
        $cache_key = 'query:' . md5($query_key);
        return self::get($cache_key);
    }

    /**
     * Invalidate cache by pattern
     */
    public static function invalidatePattern($pattern) {
        self::initRedis();
        if (self::$redis) {
            $full_pattern = self::$cache_prefix . $pattern;
            $keys = self::$redis->keys($full_pattern);
            if (!empty($keys)) {
                $result = self::$redis->del($keys);
                return $result > 0;
            }
        }
        return false;
    }

    /**
     * Set longer TTL for static data
     */
    public static function setLongCache($key, $value, $ttl = 3600) { // 1 hour
        return self::set($key, $value, $ttl);
    }

    /**
     * Set shorter TTL for dynamic data
     */
    public static function setShortCache($key, $value, $ttl = 60) { // 1 minute
        return self::set($key, $value, $ttl);
    }
}
?>