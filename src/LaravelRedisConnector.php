<?php

namespace Halaei\Lqrm;

use Illuminate\Support\Arr;
use Illuminate\Queue\Connectors\RedisConnector as BaseConnector;

class LaravelRedisConnector extends BaseConnector
{
    /**
     * Establish a queue connection.
     *
     * @param  array  $config
     * @return LaravelRedisQueue
     */
    public function connect(array $config)
    {
        return new LaravelRedisQueue(
            $this->redis, $config['queue'],
            Arr::get($config, 'connection', $this->connection),
            Arr::get($config, 'retry_after', 60),
            Arr::get($config, 'block_for', null)
        );
    }
}
