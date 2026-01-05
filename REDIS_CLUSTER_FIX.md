# Redis 集群命令兼容性修复

## 问题描述

在 Redis 集群模式下，以下命令不被 Predis 支持，会导致错误：

1. **SCAN** - 不支持跨节点扫描
2. **KEYS** - 不支持跨节点查询  
3. **CONFIG** - 集群模式不支持配置命令
4. **INFO** - 需要特殊处理才能获取信息
5. **AUTH** - 不能在连接后调用，必须在创建客户端时配置
6. **SELECT** - 集群模式只支持数据库 0
7. **DBSIZE** - 不支持跨节点统计
8. **SAVE/BGSAVE** - 集群模式不支持手动保存
9. **FLUSHDB** - 需要对所有节点执行

错误示例：
```
Fatal error: Uncaught Predis\NotSupportedException: Cannot use 'SCAN' with redis-cluster.
Fatal error: Uncaught Predis\NotSupportedException: Cannot use 'KEYS' with redis-cluster.
Fatal error: Uncaught Predis\NotSupportedException: Cannot use 'CONFIG' with redis-cluster.
Fatal error: Uncaught Predis\NotSupportedException: Cannot use 'AUTH' with redis-cluster.
Fatal error: Uncaught Predis\NotSupportedException: Cannot use 'DBSIZE' with redis-cluster.
```

## 解决方案

### 1. KEYS 命令（获取所有键）

通过遍历所有主节点分别获取键：

```php
if (isset($server['cluster']) && $server['cluster']) {
    // 获取所有主节点
    $clusterSlots = $redis->executeRaw(['CLUSTER', 'SLOTS']);
    
    // 遍历每个主节点
    foreach ($masterNodes as $node) {
        $nodeClient = new Predis\Client([
            'scheme' => $server['scheme'],
            'host'   => $node['host'],
            'port'   => $node['port'],
            'password' => $server['auth'] ?? null,
        ]);
        
        $nodeKeys = $nodeClient->keys($server['filter']);
        $keys = array_merge($keys, $nodeKeys);
        $nodeClient->disconnect();
    }
    
    $keys = array_unique($keys);
}
```

### 2. CONFIG 命令（配置管理）

在集群模式下跳过 CONFIG 命令：

```php
// index.php - 获取数据库数量
if (isset($server['cluster']) && $server['cluster']) {
    $databases = 1; // 集群只支持 db0
} else {
    $databases = $redis->config('GET', 'databases')['databases'];
}

// info.php - 重置统计
if (isset($_GET['reset'])) {
    if (!isset($server['cluster']) || !$server['cluster']) {
        $redis->config('resetstat');
    }
}
```

### 3. INFO 命令（获取服务器信息）

使用 executeRaw 方法并解析响应：

```php
if (isset($server['cluster']) && $server['cluster']) {
    $info = $redis->executeRaw(['INFO']);
    // 解析字符串格式的 INFO 响应
    if (is_string($info)) {
        $parsedInfo = array();
        $lines = explode("\\r\\n", $info);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $parsedInfo[$parts[0]] = $parts[1];
            }
        }
        $info = $parsedInfo;
    }
} else {
    $info = $redis->info();
}
```

### 4. AUTH 命令（身份认证）

在集群模式下，认证必须在创建客户端时通过 `parameters` 选项配置：

```php
// common.inc.php - 创建集群客户端
if (isset($server['cluster']) && $server['cluster']) {
    $options = array('cluster' => 'redis');
    
    // 通过 parameters 传递密码
    if (isset($server['auth'])) {
        $options['parameters'] = array('password' => $server['auth']);
    }
    
    $redis = new Predis\Client($clusterNodes, $options);
    // 不要在这里调用 $redis->auth()
}

// overview.php - 跳过集群模式的 auth() 调用
if (!isset($server['cluster']) || !$server['cluster']) {
    if (isset($server['auth'])) {
        $redis->auth($server['auth']);
    }
}
```

### 5. SELECT 命令（选择数据库）

集群模式只支持数据库 0，在其他情况下跳过 SELECT 命令：

```php
// common.inc.php 和 overview.php
if ($server['db'] != 0 && (!isset($server['cluster']) || !$server['cluster'])) {
    $redis->select($server['db']);
}
```

### 6. DBSIZE 命令（获取键数量）

在集群模式下，需要遍历所有主节点获取总数：

```php
// overview.php
if (isset($server['cluster']) && $server['cluster']) {
    $clusterSlots = $redis->executeRaw(['CLUSTER', 'SLOTS']);
    $masterNodes = array();
    foreach ($clusterSlots as $slot) {
        if (isset($slot[2])) {
            $nodeKey = $slot[2][0] . ':' . $slot[2][1];
            $masterNodes[$nodeKey] = array('host' => $slot[2][0], 'port' => $slot[2][1]);
        }
    }
    
    $totalSize = 0;
    foreach ($masterNodes as $node) {
        $nodeClient = new Predis\Client([
            'scheme' => $server['scheme'],
            'host'   => $node['host'],
            'port'   => $node['port'],
            'password' => $server['auth'] ?? null,
        ]);
        $totalSize += $nodeClient->dbSize();
        $nodeClient->disconnect();
    }
    $info[$i]['size'] = $totalSize;
} else {
    $info[$i]['size'] = $redis->dbSize();
}
```

### 7. SAVE 命令（手动保存）

集群模式不支持手动保存，显示警告信息：

```php
// save.php
if (isset($server['cluster']) && $server['cluster']) {
    echo "Warning: SAVE command is not supported in cluster mode.";
    echo "Cluster mode uses automatic persistence.";
} else {
    $redis->save();
}
```

### 8. FLUSHDB 命令（清空数据库）

在集群模式下，需要对所有节点执行 FLUSHDB：

```php
// flush.php
if (isset($server['cluster']) && $server['cluster']) {
    // Try FLUSHALL which works on clusters
    try {
        $redis->executeRaw(['FLUSHALL']);
    } catch (Exception $e) {
        // Fallback: flush each node individually
        $clusterSlots = $redis->executeRaw(['CLUSTER', 'SLOTS']);
        // ... iterate nodes and flush each one
    }
} else {
    $redis->flushdb();
}
```

## 支持的命令（无需特殊处理）

以下命令在集群模式下是完全支持的，因为它们针对单个键操作，Predis 会自动路由到正确的节点：

- **DEL** - 删除键
- **RENAME** - 重命名键
- **EXPIRE** - 设置过期时间
- **TTL** - 获取过期时间
- **PERSIST** - 移除过期时间
- **GET/SET** - 获取/设置值
- **HGET/HSET** - 哈希操作
- **SADD/SMEMBERS** - 集合操作
- **LPUSH/LRANGE** - 列表操作
- **ZADD/ZRANGE** - 有序集合操作

## 修改的文件

1. **index.php** - KEYS 命令和 CONFIG 命令处理
2. **info.php** - INFO 命令和 CONFIG 命令处理  
3. **overview.php** - INFO、AUTH、SELECT 和 DBSIZE 命令处理
4. **save.php** - SAVE 命令处理
5. **flush.php** - FLUSHDB 命令处理
6. **includes/common.inc.php** - AUTH 和 SELECT 命令处理
7. **includes/login_acl.inc.php** - ACL 认证在集群模式下的处理
3. **overview.php** - INFO 命令处理

## 工作原理

1. **检测集群模式**：判断 `$server['cluster']` 是否为 true
2. **获取节点列表**：使用 `CLUSTER SLOTS` 命令获取所有主节点
3. **遍历节点**：对每个主节点创建独立连接
4. **执行 KEYS**：在每个节点上执行 `KEYS` 命令
5. **合并结果**：将所有节点的键合并并去重
6. **异常处理**：如果某个节点失败，继续处理其他节点

## 性能考虑

- **集群模式**：
  - 需要连接所有主节点（通常 3-6 个）
  - 每个节点单独执行 KEYS 命令
  - 性能取决于节点数量和每个节点的键数量
  - 建议在大型集群中使用过滤器限制结果

- **单节点模式**：
  - 优先使用 SCAN 命令（更高效）
  - 也可配置使用 KEYS 命令

## 使用方式

重建 Docker 镜像并运行：

```bash
docker build -t phpredisadmin:1.28 .

docker run -d \
  -e REDIS_1_HOST=22.62.96.38 \
  -e REDIS_1_PORT=16379 \
  -e REDIS_1_NAME=SBTDRedis \
  -e REDIS_1_SCHEME=tcp \
  -e REDIS_1_AUTH=SCredis_2020 \
  -e ADMIN_USER=sczxjc \
  -e ADMIN_PASS=ScsGcc_chengdu2o25 \
  -e REDIS_1_CLUSTER=true \
  -e REDIS_1_CLUSTER_NODES="22.62.96.38:16379,22.62.96.38:16380,22.62.96.39:16379,22.62.96.39:16380,22.62.96.40:16379,22.62.96.40:16380" \
  -p 31136:80 \
  --name phpredisadmin \
  -h phpredisadmin \
  --restart=always \
  phpredisadmin:1.28
```

## 故障排查

如果仍然遇到 SCAN 相关错误：

1. 确保 Docker 镜像已重建（包含最新代码）
2. 检查 `REDIS_1_CLUSTER` 环境变量是否设置为 `true`
3. 查看容器日志：`docker logs phpredisadmin`
4. 清除容器并重新创建：
   ```bash
   docker rm -f phpredisadmin
   docker run ... (使用上述完整命令)
   ```

## 相关代码位置

- [index.php](index.php#L8-L26) - 主要修改
- [includes/common.inc.php](includes/common.inc.php#L133-L178) - 集群连接逻辑
- [includes/config.environment.inc.php](includes/config.environment.inc.php) - 集群环境变量处理
