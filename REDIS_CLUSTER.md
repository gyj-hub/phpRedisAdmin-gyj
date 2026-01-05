# Redis 集群支持配置说明

## 问题描述
当连接 Redis 集群中的某个节点时，默认只能看到该节点的 key，无法看到其他节点的 key。

## 解决方案
已添加 Redis 集群模式支持，可以自动连接到所有集群节点并查看完整的数据。

## 配置方法

### 1. 修改配置文件

编辑 `includes/config.inc.php`（如果不存在，从 `includes/config.sample.inc.php` 复制），在服务器配置中添加集群选项：

```php
$config = array(
  'servers' => array(
    array(
      'name'   => 'Redis Cluster',
      'host'   => '192.168.50.22',  // 集群中任意一个节点的地址
      'port'   => 6379,
      'auth'   => '123456',          // 如果有密码
      'cluster' => true,             // 启用集群模式
      
      // 可选：指定其他集群节点（如果需要明确指定）
      'cluster_nodes' => array(
        array('host' => '192.168.50.23', 'port' => 6379),
        array('host' => '192.168.50.24', 'port' => 6379),
      ),
    ),
  ),
  // ... 其他配置
);
```

### 2. 配置选项说明

- `cluster`: 设置为 `true` 启用集群模式
- `cluster_nodes`: （可选）额外的集群节点列表。如果不指定，Predis 会自动从主节点发现其他节点
- 在集群模式下，Predis 会自动处理槽位路由，确保能访问所有节点的数据

### 3. 使用 Docker 环境变量配置

如果使用 Docker 部署，可以通过环境变量配置：

```bash
docker run -e REDIS_1_HOST=192.168.50.22 \
           -e REDIS_1_PORT=6379 \
           -e REDIS_1_AUTH=123456 \
           -e REDIS_1_CLUSTER=true \
           -e REDIS_1_CLUSTER_NODES="192.168.50.23:6379,192.168.50.24:6379" \
           -p 80:80 \
           erikdubbelboer/phpredisadmin
```

环境变量说明：
- `REDIS_1_CLUSTER`: 设置为 `true` 启用集群模式
- `REDIS_1_CLUSTER_NODES`: （可选）逗号分隔的额外集群节点列表，格式为 `host1:port1,host2:port2`

或者在 `docker-compose.yml` 中配置：

```yaml
services:
  phpredisadmin:
    image: erikdubbelboer/phpredisadmin
    environment:
      - REDIS_1_HOST=192.168.50.22
      - REDIS_1_PORT=6379
      - REDIS_1_AUTH=123456
      - REDIS_1_CLUSTER=true
      - REDIS_1_CLUSTER_NODES=192.168.50.23:6379,192.168.50.24:6379
    ports:
      - "80:80"
```


## 注意事项

1. **Predis 版本**: 确保使用的 Predis 版本支持集群模式（v1.0+）
2. **网络连通性**: 确保 phpRedisAdmin 所在服务器能访问所有集群节点
3. **性能考虑**: 
   - 在集群模式下，系统会自动遍历所有主节点获取键
   - 每个节点单独执行 KEYS 命令
   - 在大型集群中建议使用过滤器限制结果
   - 合理设置 `scansize` 和 `scanmax` 参数
4. **数据库选择**: Redis 集群不支持多数据库（默认只有 db0），因此 `db` 配置选项在集群模式下无效
5. **命令限制**: 由于 Predis 集群实现的限制，SCAN 和 KEYS 等跨节点命令需要特殊处理，系统已自动处理这些情况

## 技术实现

在集群模式下，phpRedisAdmin 会：
1. 使用 `CLUSTER SLOTS` 命令获取所有主节点信息
2. 为每个主节点创建独立连接
3. 对每个节点执行 KEYS 命令
4. 合并所有节点返回的键列表并去重

这样可以确保看到集群中所有节点的数据，详见 [REDIS_CLUSTER_FIX.md](REDIS_CLUSTER_FIX.md)。

## 测试

配置完成后：
1. 访问 phpRedisAdmin 界面
2. 查看 key 列表，应该能看到所有集群节点的 key
3. 可以正常进行查询、编辑、删除等操作

## 故障排查

如果仍然只能看到单个节点的数据：

1. 检查 `cluster` 选项是否设置为 `true`
2. 确认所有集群节点网络可达
3. 检查 Redis 集群状态：`redis-cli -c -h <host> -p <port> cluster nodes`
4. 查看 PHP 错误日志，确认没有连接错误
