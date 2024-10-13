<?php
use RedisLock\RedisRedLock;

require_once 'src/RedisRedLock.php';

// 示例用法
$servers = [
    ['host' => '127.0.0.1', 'port' => 6391, 'password' => '88888888'],
    ['host' => '127.0.0.1', 'port' => 6392, 'password' => '88888888'],
    ['host' => '127.0.0.1', 'port' => 6393, 'password' => '88888888'],
];

$resourceKey = 'my_distributed_lock';


try {
    $redlock = new RedisRedLock($servers, $resourceKey);
    if ($redlock->lock()) {
        echo "成功获取锁，执行任务...\n";
        if ($redlock->lock()) {
            echo "再次获取锁，继续执行任务...\n";
        }

        if ($redlock->unlock()) {
            echo "锁已释放一次。\n";
        }

        if ($redlock->unlock()) {
            echo "锁已全部释放。\n";
        }
    } else {
        echo "获取锁失败。\n";
    }
} catch (\Exception $e) {
    var_dump($e->getMessage());
}

