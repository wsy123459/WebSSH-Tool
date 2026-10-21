# 宝塔面板部署指南（Linux）

> 宝塔面板（BT Panel）部署 WebSSH 的完整流程。
> 本文以 **PHP 8.1** 为例，其他版本把路径里的 `81` 换成对应数字（7.4 → `74`、8.0 → `80`、8.2 → `82`）。

---

## 一、理解运行方式（先看这个）

`server.php` 是**常驻命令行进程**，不是普通的 PHP 网站：

| 特性 | 说明 |
|------|------|
| 自己监听端口 | `stream_socket_server("tcp://0.0.0.0:8080")` |
| 一个进程提供全部服务 | HTTP 静态文件 + WebSocket + SSH 转发 |
| 必须常驻 | 关掉终端就停，需进程守护 |
| 不能用 PHP-FPM 跑 | 需要 `php-cli`，不是 `php-fpm` |

所以部署模型是：

```
用户 → Nginx(443) → 反代 → 127.0.0.1:8080 (php server.php 常驻)
```

---

## 二、上传源码

宝塔 → **文件** → `/www/wwwroot/` → 新建目录 `webssh`，上传：

```
/www/wwwroot/webssh/
├── server.php
├── index.html
├── start.sh
└── assets/
    ├── xterm.js
    ├── xterm.css
    └── addon-fit.js
```

设置权限（宝塔 → 文件 → 右键目录 → 权限 → 所有者 `www`、755、应用到子目录）：

```bash
chown -R www:www /www/wwwroot/webssh
chmod -R 755 /www/wwwroot/webssh
```

---

## 三、安装 ssh2 扩展

### 3.1 先试面板安装

宝塔 → **软件商店** → **PHP 8.1** → **设置** → **安装扩展** → 找 **ssh2** → 安装 → 重启 PHP。

装完一定要**用命令行验证**（面板显示已装 ≠ CLI 已加载）：

```bash
/www/server/php/81/bin/php -r "var_dump(function_exists('ssh2_connect'));"
```

必须输出 `bool(true)`。

### 3.2 面板装不了就手动编译

安装底层依赖：

```bash
yum install -y libssh2-devel        # CentOS / RHEL
apt install -y libssh2-1-dev        # Ubuntu / Debian
```

编译 ssh2 扩展：

```bash
cd /root
wget https://pecl.php.net/get/ssh2-1.4.1.tgz
tar -xzf ssh2-1.4.1.tgz
cd ssh2-1.4.1

/www/server/php/81/bin/phpize
./configure --with-php-config=/www/server/php/81/bin/php-config
make && make install
```

成功标志：

```
Installing shared extensions: /www/server/php/81/lib/php/extensions/no-debug-non-zts-xxxx/
```

写入配置：

```bash
echo "extension=ssh2.so" >> /www/server/php/81/etc/php.ini
```

验证：

```bash
/www/server/php/81/bin/php -m | grep ssh2
```

### 3.3 检查禁用函数

`server.php` 需要 `stream_socket_server` 和 `fsockopen`。

宝塔 → **软件商店** → **PHP 8.1** → **设置** → **禁用函数**，若列表里有就删掉。

找不到说明没被禁用，直接跳过。

验证：

```bash
/www/server/php/81/bin/php -r "var_dump(function_exists('stream_socket_server'), function_exists('fsockopen'));"
```

两个都得是 `true`。

---

## 四、启动测试

先手动跑一次，确认能起来：

```bash
cd /www/wwwroot/webssh
/www/server/php/81/bin/php server.php
```

看到这行即成功：

```
WebSSH server: http://localhost:8080
```

另开终端验证：

```bash
curl -i http://127.0.0.1:8080/
```

返回 `HTTP/1.1 200 OK`，然后 `Ctrl+C` 停掉。

### 端口被占用怎么办

```bash
ss -lntp | grep 8080
```

换端口启动：

```bash
PORT=18080 /www/server/php/81/bin/php server.php
```

或直接改 `server.php` 第 12 行：

```php
$PORT = 18080;
```

---

## 五、升级 libssh2（当握手失败时）

如果连接时报：

```
SSH 握手失败：目标端口未返回 SSH 服务
SSH connection(-5): Unable to exchange encryption keys
```

说明 libssh2 太旧（1.8.x 不认识 OpenSSH 8.8+ 的 `rsa-sha2-256/512`）。

先确认版本：

```bash
/www/server/php/81/bin/php -i | grep -i "libssh2 version"
```

如果是 `1.8.0` 或更低，按下面升级：

```bash
cd /root
wget https://www.libssh2.org/download/libssh2-1.11.1.tar.gz
tar -xzf libssh2-1.11.1.tar.gz
cd libssh2-1.11.1

./configure --prefix=/usr/local/libssh2
make && make install

echo "/usr/local/libssh2/lib" > /etc/ld.so.conf.d/libssh2.conf
ldconfig
```

重新编译 ssh2，**指向新库**：

```bash
cd /root/ssh2-1.4.1
make clean
./configure --with-php-config=/www/server/php/81/bin/php-config --with-ssh2=/usr/local/libssh2
make && make install
```

验证链接到哪个库：

```bash
ldd /www/server/php/81/lib/php/extensions/no-debug-non-zts-*/ssh2.so | grep ssh2
```

应指向 `/usr/local/libssh2/lib/libssh2.so.1`。

---

## 六、进程守护（常驻）

宝塔 → **软件商店** → 安装 **「进程守护管理器」**（Supervisor）。

添加守护进程：

| 字段 | 填写 |
|------|------|
| 名称 | `webssh` |
| 启动用户 | `www` |
| 运行目录 | `/www/wwwroot/webssh` |
| 启动命令 | `/www/server/php/81/bin/php /www/wwwroot/webssh/server.php` |

> 若面板支持「环境变量」，可填 `PORT=8080`；否则直接改 `server.php` 第 12 行。

点「启动」，状态为 `RUNNING` 即成功。

---

## 七、Nginx 反向代理 + HTTPS

1. 宝塔 → **网站** → 添加站点，绑定域名（根目录随便给个空目录，**不要**指向源码目录）
2. 站点设置 → **SSL** → Let's Encrypt 申请证书 → 开启「强制 HTTPS」
3. 站点设置 → **反向代理** → 添加：
   - 代理名称：`webssh`
   - 目标 URL：`http://127.0.0.1:8080`
   - 发送域名：`$host`
4. **开启「WebSocket 支持」**

若面板没有 WebSocket 开关，把配置改成 [nginx-websocket.conf](nginx-websocket.conf) 的内容。

5. 宝塔 → **安全** → 放行 `80`、`443`（走反代无需暴露 8080）

> ⚠️ 反代必须透传 `Host`。源码会校验 WebSocket 的 `Origin` 与 `Host` 一致，不一致会返回 403。

---

## 八、验证

浏览器打开 `https://你的域名`：

1. 出现登录页
2. 填主机 / 端口 / 用户名 / 密码，点连接
3. 进入终端，能执行命令
4. 点「文件」能浏览目标服务器目录
5. 刷新页面，「最近连接」自动加载

---

## 九、常见问题

| 现象 | 原因 | 解决 |
|------|------|------|
| 点连接报「与服务器的连接中断」 | **ssh2 扩展没装 / CLI 未加载** | 第三节，装完**重启进程** |
| 启动报 `xxx() has been disabled` | 禁用函数未解除 | 第 3.3 节 |
| 启动报 `Address already in use` | 端口被占用 | 换端口 |
| 502 Bad Gateway | 后端进程没跑 | 进程守护里启动 |
| 连接报 `-5 exchange keys` | libssh2 太旧 | 第五节 |
| 页面能开，点连接无反应 | 反代没开 WebSocket | 第七节 |
| `Module "zip" is already loaded` | 无害警告 | 忽略 |
| 最近连接不显示 | `data/` 无写权限，或没勾「保存此连接」 | `chown -R www:www .../data` |

---

## 十、安全加固

这个工具**没有访问认证**，务必：

- 站点加「密码访问」（Basic Auth）或限制来源 IP
- 必须启用 HTTPS
- 不要用 root 做日常连接
- `data/` 目录里的密码是 base64 编码（非加密），注意保护
