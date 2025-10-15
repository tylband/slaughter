<?php
class RedisSessionHandler {
    private $redis;
    private $session_prefix = 'shms_session:';
    private $lifetime;

    public function __construct($redis = null) {
        global $redis;
        $this->redis = $redis;
        $this->lifetime = ini_get('session.gc_maxlifetime');
    }

    public function open($save_path, $session_name) {
        return true;
    }

    public function close() {
        return true;
    }

    public function read($session_id) {
        if (!$this->redis) {
            return '';
        }

        $key = $this->session_prefix . $session_id;
        $data = $this->redis->get($key);

        return $data ? $data : '';
    }

    public function write($session_id, $session_data) {
        if (!$this->redis) {
            return false;
        }

        $key = $this->session_prefix . $session_id;

        // Set session with lifetime
        $result = $this->redis->setex($key, $this->lifetime, $session_data);
        return $result === true || ($result instanceof Predis\Response\Status && $result->getPayload() === 'OK');
    }

    public function destroy($session_id) {
        if (!$this->redis) {
            return false;
        }

        $key = $this->session_prefix . $session_id;
        $result = $this->redis->del($key);
        return $result > 0;
    }

    public function gc($maxlifetime) {
        // Redis handles expiration automatically with setex
        return true;
    }

    /**
     * Get all active sessions for a user
     */
    public function getUserSessions($username) {
        if (!$this->redis) {
            return [];
        }

        $pattern = $this->session_prefix . '*';
        $keys = $this->redis->keys($pattern);
        $user_sessions = [];

        foreach ($keys as $key) {
            $session_data = $this->redis->get($key);
            if ($session_data) {
                $data = unserialize($session_data);
                if (isset($data['username']) && $data['username'] === $username) {
                    $user_sessions[] = [
                        'session_id' => str_replace($this->session_prefix, '', $key),
                        'created' => isset($data['created']) ? $data['created'] : time(),
                        'last_activity' => $this->redis->ttl($key) + time()
                    ];
                }
            }
        }

        return $user_sessions;
    }

    /**
      * Destroy all sessions for a user
      */
    public function destroyUserSessions($username) {
        if (!$this->redis) {
            return false;
        }

        $pattern = $this->session_prefix . '*';
        $keys = $this->redis->keys($pattern);
        $destroyed_count = 0;

        foreach ($keys as $key) {
            $session_data = $this->redis->get($key);
            if ($session_data) {
                $data = unserialize($session_data);
                if (isset($data['username']) && $data['username'] === $username) {
                    $this->redis->del($key);
                    $destroyed_count++;
                }
            }
        }

        return $destroyed_count > 0;
    }

    /**
     * Get session statistics
     */
    public function getSessionStats() {
        if (!$this->redis) {
            return [
                'total_sessions' => 0,
                'redis_connected' => false
            ];
        }

        $pattern = $this->session_prefix . '*';
        $keys = $this->redis->keys($pattern);

        return [
            'total_sessions' => count($keys),
            'redis_connected' => true
        ];
    }
}

// Initialize Redis session handler
function initRedisSessionHandler() {
    global $redis;

    if ($redis) {
        $handler = new RedisSessionHandler($redis);
        session_set_save_handler(
            [$handler, 'open'],
            [$handler, 'close'],
            [$handler, 'read'],
            [$handler, 'write'],
            [$handler, 'destroy'],
            [$handler, 'gc']
        );

        // Set session configuration for Redis
        ini_set('session.gc_maxlifetime', 3600); // 1 hour
        ini_set('session.cookie_lifetime', 0); // Session cookie
        ini_set('session.gc_probability', 1);
        ini_set('session.gc_divisor', 100);

        return true;
    }

    return false;
}
?>