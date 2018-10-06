<?php

namespace Halaei\Lqrm;

use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;

class LaravelRedisQueueServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot(QueueManager $manager)
    {
        $manager->addConnector('bredis', function() {
            return new LaravelRedisConnector($this->app['redis']);
        });
    }
}
