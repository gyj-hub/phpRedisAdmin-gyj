# phpRedisAdmin 简体中文说明

phpRedisAdmin 是一个简单的 Web 界面，用于管理 [Redis](http://redis.io/) 数据库。
本项目基于 [Erik Dubbelboer](https://github.com/ErikDubbelboer/) 的开源版本进行二次开发，增加了更多实用功能。

## 新增功能

**类型过滤功能**

在原有基础上，新增了页面过滤功能。现在你可以在界面上根据 Redis 键的类型（如 hash、set、list、string、zset 等）进行筛选和过滤，方便快速定位和管理特定类型的数据。

**Redis 集群支持**

新增了 Redis 集群模式支持。当连接到 Redis 集群时，可以查看和管理所有集群节点的数据，而不仅仅是单个节点。详细配置方法请参考 [Redis 集群配置说明](REDIS_CLUSTER.md)。

主要特性：
- 自动连接到所有集群节点
- 支持跨节点查询和操作
- 自动处理槽位路由
- 支持集群密码认证

## 安装与配置

### 使用 Docker

1. 安装 [Docker](https://www.docker.com/) 和 [Docker Compose](https://docs.docker.com/compose/)。
2. 在项目根目录下运行：
   ```
   docker-compose up --build
   ```
3. 启动后，访问 [http://localhost](http://localhost) 即可使用。
   默认管理员账号密码均为 `admin`。

### 手动安装

1. 安装 PHP（建议 7.0 及以上）、Redis 服务端和 Composer。
2. 运行 `composer install` 安装依赖。
3. 复制 `includes/config.sample.inc.php` 为 `includes/config.inc.php`，并根据实际情况修改 Redis 连接配置。
4. 启动 PHP 内置服务器：
   ```
   php -S localhost:8080
   ```
5. 浏览器访问 [http://localhost:8080](http://localhost:8080)。

## 环境变量说明（Docker 部署）

- `REDIS_1_HOST`：Redis 服务器主机名
- `REDIS_1_NAME`：Redis 服务器名称
- `REDIS_1_PORT`：Redis 端口
- `REDIS_1_SCHEME`：连接协议（tcp 或 tls）
- `REDIS_1_AUTH`：Redis 密码
- `REDIS_1_CLUSTER`：设置为 `true` 启用 Redis 集群模式
- `REDIS_1_CLUSTER_NODES`：逗号分隔的额外集群节点列表（格式：`host1:port1,host2:port2`）
- `ADMIN_USER`：登录用户名
- `ADMIN_PASS`：登录密码

## 主要功能

- 浏览、查询、编辑、删除 Redis 键值
- 支持多种数据类型（string、hash、set、list、zset）
- 支持类型过滤，快速筛选特定类型数据
- 支持多 Redis 实例管理
- 支持导入、导出、重命名、TTL 设置等操作

## 致谢

本项目基于 [Erik Dubbelboer/phpRedisAdmin](https://github.com/ErikDubbelboer/phpRedisAdmin) 开发。
图标来自 [Yusuke Kamiyamane](http://p.yusukekamiyamane.com/)。
Favicon 来源 [redis-io](https://github.com/antirez/redis-io/blob/master/public/images/favicon.png)。
