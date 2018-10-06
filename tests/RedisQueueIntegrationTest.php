<?php

use Halaei\Lqrm\LaravelRedisQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Support\InteractsWithTime;
use Orchestra\Testbench\TestCase;

class RedisQueueIntegrationTest extends TestCase
{
    use InteractsWithRedis, InteractsWithTime;

    /**
     * @var LaravelRedisQueue
     */
    private $queue;

    public function setUp()
    {
        parent::setUp();
    }

    public function tearDown()
    {
        parent::tearDown();
        $this->tearDownRedis();
    }

    public function testExpiredJobsArePopped()
    {
        $this->setQueue();

        $jobs = [
            new RedisQueueIntegrationTestJob(0),
            new RedisQueueIntegrationTestJob(1),
            new RedisQueueIntegrationTestJob(2),
            new RedisQueueIntegrationTestJob(3),
        ];

        $this->queue->later(1000, $jobs[0]);
        $this->queue->later(-200, $jobs[1]);
        $this->queue->later(-300, $jobs[2]);
        $this->queue->later(-100, $jobs[3]);

        $this->assertEquals($jobs[2], unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $this->assertEquals($jobs[1], unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $this->assertEquals($jobs[3], unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $this->assertNull($this->queue->pop());

        $this->assertEquals(1, $this->redis->connection()->zcard('queues:default:delayed'));
        $this->assertEquals(3, $this->redis->connection()->zcard('queues:default:reserved'));
    }

    public function testPopProperlyPopsJobOffOfRedis()
    {
        $this->setQueue();

        // Push an item into queue
        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->push($job);

        // Pop and check it is popped correctly
        $before = $this->currentTime();
        /** @var RedisJob $redisJob */
        $redisJob = $this->queue->pop();
        $after = $this->currentTime();

        $this->assertEquals($job, unserialize(json_decode($redisJob->getRawBody())->data->command));
        $this->assertEquals(1, $redisJob->attempts());
        $this->assertEquals($job, unserialize(json_decode($redisJob->getReservedJob())->data->command));
        $this->assertEquals(1, json_decode($redisJob->getReservedJob())->attempts);
        $this->assertEquals($redisJob->getJobId(), json_decode($redisJob->getReservedJob())->id);

        // Check reserved queue
        $this->assertEquals(1, $this->redis->connection()->zcard('queues:default:reserved'));
        $result = $this->redis->connection()->zrangebyscore('queues:default:reserved', -INF, INF, ['withscores' => true]);
        $reservedJob = array_keys($result)[0];
        $score = $result[$reservedJob];
        $this->assertLessThanOrEqual((int)$score, $before + 60);
        $this->assertGreaterThanOrEqual((int)$score, $after + 60);
        $this->assertEquals($job, unserialize(json_decode($reservedJob)->data->command));
    }

    public function testPopProperlyPopsDelayedJobOffOfRedis()
    {
        $this->setQueue();
        // Push an item into queue
        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->later(-10, $job);

        // Pop and check it is popped correctly
        $before = $this->currentTime();
        $this->assertEquals($job, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $after = $this->currentTime();

        // Check reserved queue
        $this->assertEquals(1, $this->redis->connection()->zcard('queues:default:reserved'));
        $result = $this->redis->connection()->zrangebyscore('queues:default:reserved', -INF, INF, ['withscores' => true]);
        $reservedJob = array_keys($result)[0];
        $score = $result[$reservedJob];
        $this->assertLessThanOrEqual((int)$score, $before + 60);
        $this->assertGreaterThanOrEqual((int)$score, $after + 60);
        $this->assertEquals($job, unserialize(json_decode($reservedJob)->data->command));
    }

    public function testNotExpireJobsWhenExpireLarge()
    {
        $this->setQueue(0, 10000000);

        // Make an expired reserved job
        $failed = new RedisQueueIntegrationTestJob(-20);
        $this->queue->push($failed);
        $beforeFailPop = $this->currentTime();
        $this->queue->pop();
        $afterFailPop = $this->currentTime();

        // Push an item into queue
        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->push($job);

        // Pop and check it is popped correctly
        $before = $this->currentTime();
        $this->assertEquals($job, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $after = $this->currentTime();

        // Check reserved queue
        $this->assertEquals(2, $this->redis->connection()->zcard('queues:default:reserved'));
        $result = $this->redis->connection()->zrangebyscore('queues:default:reserved', -INF, INF, ['withscores' => true]);

        foreach ($result as $payload => $score) {
            $command = unserialize(json_decode($payload)->data->command);
            $this->assertInstanceOf(RedisQueueIntegrationTestJob::class, $command);
            $this->assertContains($command->i, [10, -20]);
            if ($command->i == 10) {
                $this->assertLessThanOrEqual((int)$score, $before + 10000000);
                $this->assertGreaterThanOrEqual((int)$score, $after + 10000000);
            } else {
                $this->assertLessThanOrEqual((int)$score, $beforeFailPop + 10000000);
                $this->assertGreaterThanOrEqual((int)$score, $afterFailPop + 10000000);
            }
        }
    }

    public function testExpireJobsWhenExpireSet()
    {
        $this->setQueue(0, 30);

        // Push an item into queue
        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->push($job);

        // Pop and check it is popped correctly
        $before = $this->currentTime();
        $this->assertEquals($job, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $after = $this->currentTime();

        // Check reserved queue
        $this->assertEquals(1, $this->redis->connection()->zcard('queues:default:reserved'));
        $result = $this->redis->connection()->zrangebyscore('queues:default:reserved', -INF, INF, ['withscores' => true]);
        $reservedJob = array_keys($result)[0];
        $score = $result[$reservedJob];
        $this->assertLessThanOrEqual((int)$score, $before + 30);
        $this->assertGreaterThanOrEqual((int)$score, $after + 30);
        $this->assertEquals($job, unserialize(json_decode($reservedJob)->data->command));
    }

    public function testRelease()
    {
        $this->setQueue();

        //push a job into queue
        $job = new RedisQueueIntegrationTestJob(30);
        $this->queue->push($job);

        //pop and release the job
        /** @var \Illuminate\Queue\Jobs\RedisJob $redisJob */
        $redisJob = $this->queue->pop();
        $before = $this->currentTime();
        $redisJob->release(1000);
        $after = $this->currentTime();

        //check the content of delayed queue
        $this->assertEquals(1, $this->redis->connection()->zcard('queues:default:delayed'));

        $results = $this->redis->connection()->zrangebyscore('queues:default:delayed', -INF, INF, ['withscores' => true]);

        $payload = array_keys($results)[0];

        $score = $results[$payload];

        $this->assertGreaterThanOrEqual($before + 1000, (int)$score);
        $this->assertLessThanOrEqual($after + 1000, (int)$score);

        $decoded = json_decode($payload);

        $this->assertEquals(1, $decoded->attempts);
        $this->assertEquals($job, unserialize($decoded->data->command));

        //check if the queue has no ready item yet
        $this->assertNull($this->queue->pop());
    }

    public function testReleaseInThePast()
    {
        $this->setQueue();
        $job = new RedisQueueIntegrationTestJob(30);
        $this->queue->push($job);

        /** @var RedisJob $redisJob */
        $redisJob = $this->queue->pop();
        $redisJob->release(-3);

        $this->assertInstanceOf(RedisJob::class, $this->queue->pop());
    }

    public function testDelete()
    {
        $this->setQueue();

        $job = new RedisQueueIntegrationTestJob(30);
        $this->queue->push($job);

        /** @var \Illuminate\Queue\Jobs\RedisJob $redisJob */
        $redisJob = $this->queue->pop();

        $redisJob->delete();

        $this->assertEquals(0, $this->redis->connection()->zcard('queues:default:delayed'));
        $this->assertEquals(0, $this->redis->connection()->zcard('queues:default:reserved'));
        $this->assertEquals(0, $this->redis->connection()->llen('queues:default'));

        $this->assertNull($this->queue->pop());
    }

    public function testSize()
    {
        $this->setQueue();
        $this->assertEquals(0, $this->queue->size());
        $this->queue->push(new RedisQueueIntegrationTestJob(1));
        $this->assertEquals(1, $this->queue->size());
        $this->queue->later(60, new RedisQueueIntegrationTestJob(2));
        $this->assertEquals(2, $this->queue->size());
        $this->queue->push(new RedisQueueIntegrationTestJob(3));
        $this->assertEquals(3, $this->queue->size());
        $job = $this->queue->pop();
        $this->assertEquals(3, $this->queue->size());
        $job->delete();
        $this->assertEquals(2, $this->queue->size());
    }

    public function testBlockingPopNothing()
    {
        $this->setQueue(1);
        $t = microtime(true);
        $this->assertNull($this->queue->pop());
        $this->assertGreaterThanOrEqual($t + 1, microtime(true));
    }

    public function testBlockingPopSomethingReady()
    {
        $this->setQueue(10);
        $this->queue->push(new RedisQueueIntegrationTestJob(15));
        /** @var RedisJob $job */
        $job = $this->queue->pop();
        $this->assertInstanceOf(RedisJob::class, $job);
        $this->assertEquals(1, $this->redis->connection()->zcard('queues:default:reserved'));
        $this->assertEquals(1, Arr::get(json_decode($job->getReservedJob(), true), 'attempts'));
        $job->delete();
        $this->assertEquals(0, $this->redis->connection()->zcard('queues:default:reserved'));
        $this->assertEquals(0, $this->redis->connection()->zcard('queues:default:delayed'));
    }

    public function testBlockingPopSomethingPushedLater()
    {
        $this->tearDownRedis();
        if (pcntl_fork()) {
            //parent
            $this->setQueue(20, 60);
            $this->assertEquals(32, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command)->i);
        } else {
            // child
            sleep(2);
            try {
                $this->setQueue(10, 60);
                $this->queue->push(new RedisQueueIntegrationTestJob(32));
            } finally {
                die;
            }
        }
    }

    public function testBlockingPopADelayedJob()
    {
        Carbon::setTestNow();
        $this->setQueue(3, 60);
        $this->queue->later(1, new RedisQueueIntegrationTestJob(33));
        $this->assertEquals(33, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command)->i);
    }

    private function setQueue($blockFor = 0, $retryAfter = 60)
    {
        $this->queue = new LaravelRedisQueue($this->getRedis(), 'default', null, $retryAfter, $blockFor);
        $this->queue->setContainer(Mockery::mock(Container::class));
    }
}

class RedisQueueIntegrationTestJob
{
    public $i;

    public function __construct($i)
    {
        $this->i = $i;
    }

    public function handle()
    {
    }
}
