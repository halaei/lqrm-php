# PHP Driver for Laravel Queue Redis Module (lqrm)

This is PHP driver for the ["Laravel Queue Redis Driver"](https://github.com/halaei/lqrm).

# Why use lqrm?
Because:
1. Blocking pop is now more reliable than before.
2. Blocking pop now works on delayed and reserved jobs as well.
3. Timer for delayed and reserved jobs is server side, with milliseconds precision. This means
you don't need to worry about syncing your php and redis servers, in case your projects are 
distributed accross different servers. Moreover, this makes `retry_after` and `block_for`
configurations independent of each other.
4. Laravel queue can now be available for other programming languages and frameworks as well.
Feel free to port it to your favorite ones.


## Installation

First install the package via composer:

    composer require halaei/lqrm
    
Then add the service provider to your config/app.php:

    Halaei\Lqrm\LaravelRedisQueueServiceProvider::class

To use this package with `laravel/horizon`, instead use the following service provider:

Finally, change driver of your redis queue connections to `lqrm` in app/queue.php, and set block_for to some small
integer:

    'redis' => [
        'driver'      => 'lqrm',        // <<< switch to lqrm driver
        'connection'  => 'default',
        'queue'       => 'default',
        'retry_after' => 90,
        'block_for'   => 10,            // <<< set block_for
    ],

Please note that if you need to increase 'block_for' in the config array above, you should increase --timeout in your
`queue:work` commands as well.

## Detailed Consideration

Here are the behaviour changes if you move from the original Laravel 5.7 driver to `lqrm`.
I believe the changes are for the best and probably have ignorable affects on your projects.

1. In 5.7, setting `block_for` to 0 means blocking for ever.
In lqrm setting `blocking_for` to 0 or any value less than 1 means not to block at all.
2. Using 5.7 with horizon, calling pop() can trigger JobsMigrated() event, if it migrates jobs from delayed and
reserved queue. `lqrm` does not trigger this event.
3. In 5.7, you could optionally disable migration of expired jobs from reserved queue. This is
not supported in `lqrm`.
