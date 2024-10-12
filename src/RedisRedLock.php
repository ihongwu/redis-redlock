<?php
namespace RedisLock;

class RedisRedLock
{
    /**
     * Redis配置/实例数组
     * @var array
     */
    private $redisInstances = [];

    /**
     * 达到多数实例的锁定数目
     * @var int
     */
    private $quorum;

    /**
     * 锁的 TTL 默认为 30 秒
     * @var int
     */
    private $lockTtl;

    /**
     * 锁的场景
     * @var string
     */
    private $resourceKey;

    /**
     * 锁的值
     * @var string
     */
    private $lockToken;

    /**
     * 锁的计数器
     * @var int
     */
    private $lockCount = 0;

    /**
     * 尝试获取锁的次数，默认200次
     * @var int
     */
    private $retryCount;

    /**
     * Redis 连接超时时间，默认 50毫秒
     * @var float
     */
    private $connectTimeout;

    /**
     * RedisRedLock constructor.
     * @param array $servers  Redis配置/实例数组
     * @param string $resourceKey 锁场景
     * @param int $lockTtl 锁超时时间，默认30秒
     * @param int $retryCount 重试次数，默认200次
     * @param float $connectTimeout  连接超时时间，默认50毫秒
     * @throws Exception
     */
    public function __construct($servers, $resourceKey, $lockTtl = 30, $retryCount = 200, $connectTimeout = 0.05)
    {
        $this->redisInstances = [];
        $this->lockCount = 0;

        // 检查服务器信息
        $this->checkRedisConfig($servers);

        // 服务器数量
        $servercount = count($servers);
        if ($servercount % 2 != 1) {
            throw new \Exception('The number of Redis servers needs to be an odd number');
        }

        $this->resourceKey = $resourceKey; // 锁定的资源 key 在实例化时传入
        $this->lockTtl = $lockTtl;
        $this->lockToken = uniqid('',true) . '.' . rand(100000,999999); // 每个实例持有唯一的 token
        $this->retryCount = $retryCount; // 重试获取锁的次数
        $this->connectTimeout = $connectTimeout; // 设置 Redis 连接超时时间

        // 达到多数实例的锁定数目
        $this->quorum = floor($servercount / 2) + 1;

        // 连接Redis
        $this->initInstances($servers);
    }

    /**
     * 检查传入的Redis配置信息或示例是否重复
     * @param array $servers Redis配置/实例数组
     * @throws Exception
     */
    private function checkRedisConfig($servers){
        $redisconfigs = [];
        foreach ($servers as $confg) {

            $isarr = is_array($confg);
            if ( $isarr ) {
                $hostp = $confg['host'].':'.$confg['port'];
                $errmsg = 'Redis '.$hostp;
            } else {
                $hostp = get_class($confg) .'#'.spl_object_hash($confg);
                $errmsg = $hostp;
            }

            if (!in_array($hostp,$redisconfigs)) {
                $redisconfigs[] = $hostp;
            } else {
                throw new \Exception($errmsg.' already exists');
            }
        }
    }

    /**
     * 初始化Redis
     * @param array $servers Redis配置/实例数组
     * @throws Exception
     */
    private function initInstances($servers){
        // 初始化 Redis 客户端
        foreach ($servers as $server) {
            if (is_array($server)) {
                $redis = new \Redis();
                try {
                    $redis->connect($server['host'], $server['port'], $this->connectTimeout); // 设置连接超时时间
                    if (!empty($server['password'])) {
                        $redis->auth($server['password']);
                    }
                    $this->redisInstances[] = $redis;
                } catch (\Exception $e) {
                    // throw new \Exception("Unable to connect to Redis instance: {$server['host']}:{$server['port']} Error message:" . $e->getMessage());
                }
            } else {
                $this->redisInstances[] = $server;
            }
        }

        if (count($this->redisInstances) < $this->quorum) {
            throw new \Exception('The number of successfully connected Redis instances is less than the required quorum: ' . $this->quorum);
        }
    }

    /**
     * 重入锁检测，判断当前客户端是否持有锁
     * @return bool
     */
    private function isLocked()
    {
        foreach ($this->redisInstances as $redis) {
            $lockValue = $redis->get($this->resourceKey);
            if ($lockValue !== $this->lockToken) {
                return false;
            }
        }
        return $this->lockCount > 0;
    }

    /**
     * 删除锁信息
     * @return bool
     */
    private function delLock(){
        $luaScript = '
                    if redis.call("get", KEYS[1]) == ARGV[1] then
                        return redis.call("del", KEYS[1])
                    else
                        return 0
                    end';

        foreach ($this->redisInstances as $redis) {
            $redis->eval($luaScript, [$this->resourceKey, $this->lockToken], 1);
            $redis->close();
        }
        return true;
    }

    /**
     * 获取分布式锁（支持重入和重试）
     * @return bool
     */
    public function lock()
    {
        if ($this->isLocked()) {
            $this->lockCount++;
            return true;
        }

        $attempts = 0;
        while ($attempts < $this->retryCount) {
            $acquired = 0;
            $startTime = microtime(true) * 1000;

            foreach ($this->redisInstances as $redis) {
                $isLocked = $redis->set($this->resourceKey, $this->lockToken, ['NX', 'EX' => $this->lockTtl]);
                if ($isLocked) {
                    $acquired++;
                } else {
                    // 只要有一台服务器尝试加锁失败，说明上一个客户端的锁还没有删除干净，直接删除当前客户端有可能加的锁，避免锁混乱，混乱后会导致上一个客户端无法删除部分锁
                    $this->delLock();
                    break;
                }
            }

            $elapsedTime = microtime(true) * 1000 - $startTime;

            if ($acquired >= $this->quorum && $elapsedTime < $this->lockTtl * 1000) {
                $this->lockCount++;
                return true;
            }

            $attempts++;
            usleep(100 * 1000);
        }
        // 加锁失败，全部实例解锁
        $this->delLock();
        return false;
    }

    /**
     * 释放分布式锁，返回是否解锁成功
     * @param bool $force 是否强制删除所有锁，包括重入的锁，默认false
     * @return bool
     */
    public function unlock($force = false)
    {

        if ($force) {
            $this->lockCount = 0;
            return $this->delLock();
        }

        if ($this->lockCount > 0) {
            $this->lockCount--;

            if ($this->lockCount === 0) {
                $this->delLock();
            }
            return true;
        }
        return false;
    }
}