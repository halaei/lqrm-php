<?php

namespace Halaei\Lqrm;

use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Predis\Command\RawCommand;

class LaravelRedisQueue extends RedisQueue implements QueueContract
{
    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload
     * @param  string  $queue
     * @param  array   $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $this->getConnection()->executeCommand(new RawCommand([
            'laravel.push', $this->getQueue($queue), $payload
        ]));

        return json_decode($payload, true)['id'] ?? null;
    }

    /**
     * Push a raw job onto the queue after a delay.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string  $payload
     * @param  string  $queue
     * @return mixed
     */
    protected function laterRaw($delay, $payload, $queue = null)
    {
        $this->getConnection()->executeCommand(new RawCommand([
            'laravel.later', $this->getQueue($queue).':delayed', $this->secondsUntil($delay) * 1000, $payload
        ]));

        return json_decode($payload, true)['id'] ?? null;
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        $prefixed = $this->getQueue($queue);
        list($job, $reserved) = $this->getConnection()->executeCommand(new RawCommand([
            'laravel.pop',
            $prefixed, $prefixed.':delayed', $prefixed.':reserved',
            $this->secondsUntil($this->retryAfter) * 1000, $this->blockFor * 1000
        ]));

        if ($reserved) {
            return new RedisJob(
                $this->container, $this, $job,
                $reserved, $this->connectionName, $queue ?: $this->default
            );
        }
    }

    /**
     * Delete a reserved job from the queue.
     *
     * @param  string  $queue
     * @param  \Illuminate\Queue\Jobs\RedisJob  $job
     * @return void
     */
    public function deleteReserved($queue, $job)
    {
        $this->getConnection()->executeCommand(new RawCommand([
            'laravel.delete', $this->getQueue($queue).':reserved', $job->getReservedJob()
        ]));
    }

    /**
     * Delete a reserved job from the reserved queue and release it.
     *
     * @param  string  $queue
     * @param  \Illuminate\Queue\Jobs\RedisJob  $job
     * @param  int  $delay
     * @return void
     */
    public function deleteAndRelease($queue, $job, $delay)
    {
        $queue = $this->getQueue($queue);

        $this->getConnection()->executeCommand(new RawCommand([
            'laravel.release', $queue.':delayed', $queue.':reserved',
            $job->getReservedJob(), $this->secondsUntil($delay) * 1000
        ]));
    }

    /**
     * Migrate the delayed jobs that are ready to the regular queue.
     *
     * @deprecated
     *
     * @param  string  $from
     * @param  string  $to
     * @return array
     */
    public function migrateExpiredJobs($from, $to)
    {
        return [];
    }
}
