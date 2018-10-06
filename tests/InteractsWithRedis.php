<?php

use Illuminate\Redis\RedisManager;

trait InteractsWithRedis
{
    /**
     * @var bool
     */
    private static $connectionFailedOnceWithDefaultsSkip = false;

    /**
     * @var RedisManager
     */
    private $redis;

    public function setUpRedis()
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = getenv('REDIS_PORT') ?: 9999;

        if (static::$connectionFailedOnceWithDefaultsSkip) {
            $this->markTestSkipped('Trying default host/port failed, please set environment variable REDIS_HOST & REDIS_PORT to enable '.__CLASS__);

            return;
        }

        $this->redis = new RedisManager($this->app, 'predis', [
            'cluster' => false,
            'default' => [
                'host' => $host,
                'port' => $port,
                'database' => 5,
                'timeout' => 0.5,
            ],
        ]);

        try {
            $this->redis->connection()->flushdb();
        } catch (\Exception $e) {
            if ($host === '127.0.0.1' && $port === 6379 && getenv('REDIS_HOST') === false) {
                $this->markTestSkipped('Trying default host/port failed, please set environment variable REDIS_HOST & REDIS_PORT to enable '.__CLASS__);
                static::$connectionFailedOnceWithDefaultsSkip = true;

                return;
            }
        }
    }

    public function getRedis()
    {
        if (! $this->redis) {
            $this->setUpRedis();
        }
        return $this->redis;
    }
    public function tearDownRedis()
    {
        if ($this->redis) {
            $this->redis->connection()->flushdb();
            $this->redis = null;
        }
    }
}
