# PHP版本redlock
### PHP版本的Redis红锁，已支持一下特性：  
- 单机Redis
- 多机版Redis
- 传入Redis配置
- 传入Redis对象，方便在常驻内存的框架中使用，如webman
- 可重入
- 锁等待

### 注意事项
- 锁超时时间默认为30秒
- 连接Redis超时时间默认50毫秒
- 重试获取锁次数默认200次，100毫秒重试一次


### 使用方法
#### 安装
```shell
composer require ihongwu/redis-redlock
```
or
```shell
git@github.com:ihongwu/redis-redlock.git
```

#### 常规使用：传入Redis配置信息
```php
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
```

#### 常驻内存框架：传入Redis连接池，以webman为例
```php
// 配置信息
return [
    '6391' => [
        'host' => '127.0.0.1',
        'password' => '88888888',
        'port' => 6391,
        'database' => 0,
        'pool' => [
            'min' => 5,
            'max' => 50,
            'timeout' => 5
        ]
    ],
    '6392' => [
        'host' => '127.0.0.1',
        'password' => '88888888',
        'port' => 6392,
        'database' => 0,
        'pool' => [
            'min' => 5,
            'max' => 50,
            'timeout' => 5
        ]
    ],
    '6393' => [
        'host' => '127.0.0.1',
        'password' => '88888888',
        'port' => 6393,
        'database' => 0,
        'pool' => [
            'min' => 5,
            'max' => 50,
            'timeout' => 5
        ]
    ],
];
```
##### 
```php
// 不想传入连接池的，也可以直接传入Redis配置信息
$redis1 = Redis::connection('6391')->client();
$redis2 = Redis::connection('6392')->client();
$redis3 = Redis::connection('6393')->client();

$servers = [$redis1,$redis2,$redis3];

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
```

### 声明
1、该库已通过库存200，并发1000的多轮测试并正常工作，但并不意味这它100%没问题  
2、如有问题，请随时提issues或提交更正代码，在生产环境中使用它之前，请务必了解它的工作原理
