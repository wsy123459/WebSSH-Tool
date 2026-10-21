# WebSSH

> 纯 PHP 实现的网页 SSH 终端 —— 打开浏览器就能连服务器，无需安装任何客户端。

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![No Build](https://img.shields.io/badge/build-none-brightgreen.svg)](#)

一个单文件后端（`server.php`）同时承担 **HTTP 静态服务 + WebSocket 服务 + SSH/SFTP 转发**，前端用 xterm.js 渲染终端。零构建、零 CDN 依赖，丢到服务器上 `php server.php` 就能跑。

---

## ✨ 特性

- **浏览器里直接连 SSH** —— 填 IP / 端口 / 用户名 / 密码即可进入交互终端
- **单文件后端** —— `server.php` 一个进程搞定 HTTP、WebSocket、SSH 转发，无需 Nginx + PHP-FPM 组合
- **零构建、可离线** —— xterm.js 已内置到 `assets/`，不依赖任何 CDN
- **完整文件管理器** —— 浏览目录、上传（支持拖拽）、下载（分块传输带进度）、在线编辑、重命名、移动、复制、删除、修改权限、压缩 / 解压
- **连接记录** —— 按浏览器设备 ID 隔离保存，最近连接可一键重连
- **精准的连接诊断** —— 区分 DNS 解析失败、连接超时、端口拒绝、网络不可达、SSH 握手失败、认证失败、仅允许密钥登录等场景
- **移动端适配** —— 响应式布局、虚拟按键条（Esc / Tab / 方向键 / Ctrl 组合）、双指捏合缩放
- **终端尺寸同步** —— 窗口变化会通过 `ssh2_shell_resize` 同步到远端

---

## 🏗 架构

```
浏览器 (index.html + xterm.js)
   │  WebSocket /ws（JSON + base64 数据帧）
   ▼
server.php  ← 单进程常驻
   │   ① HTTP 静态服务（index.html / assets/）
   │   ② WebSocket 服务（/ws）
   │   ③ SSH / SFTP 转发
   │  php-ssh2 → libssh2
   ▼
目标服务器 (sshd)
```

密码只在 WebSocket 握手后的首条消息中传输，不会出现在 URL 或日志里。

---

## 📦 环境要求

| 依赖 | 版本要求 | 说明 |
|------|----------|------|
| PHP | **7.4+**（推荐 8.1 / 8.2） | 需要 CLI，不是 PHP-FPM |
| php-ssh2 | **1.4.0+** | 低于 1.4 没有 `ssh2_shell_resize`，终端 resize 失效 |
| libssh2 | **1.9.0+（强烈推荐 1.11.x）** | 见下方说明 |
| 系统 | Linux / macOS | Windows 未测试 |

### ⚠️ 关于 libssh2 版本（重要）

`libssh2` **1.8.x 及更早版本无法与现代 OpenSSH 完成握手**。OpenSSH 8.8+ 默认禁用了 `ssh-rsa`（SHA-1），而老版本 libssh2 不认识 `rsa-sha2-256/512`，表现是：

```
SSH 握手失败：目标端口未返回 SSH 服务
```

或者：

```
SSH connection(-5): Unable to exchange encryption keys
```

**解决办法**：升级 libssh2 到 1.11.x 后重新编译 php-ssh2。详见 [宝塔部署文档](docs/deploy-baota.md#五升级-libssh2当握手失败时)。

检查当前版本：

```bash
php -i | grep -i "libssh2 version"
ldd "$(php -r 'echo ini_get("extension_dir");')/ssh2.so" | grep ssh2
```

还需要确认：

```bash
php -m | grep ssh2                                          # 有输出
php -r "var_dump(function_exists('stream_socket_server'));"  # bool(true)
```

`stream_socket_server` 不能出现在 `php.ini` 的 `disable_functions` 里。

---

## 🚀 快速开始

```bash
git clone https://github.com/<your-name>/webssh.git
cd webssh

# 默认监听 0.0.0.0:8080
php server.php

# 自定义端口
PORT=9000 php server.php

# 或用启动脚本
./start.sh
```

浏览器打开 `http://服务器IP:8080`，填写目标主机信息即可连接。

> 想连本机就是 `127.0.0.1:22`。

---

## 🖥 部署

| 场景 | 文档 |
|------|------|
| 宝塔面板（Linux） | [docs/deploy-baota.md](docs/deploy-baota.md) |
| Nginx 反向代理 + HTTPS + WebSocket | [docs/nginx-websocket.conf](docs/nginx-websocket.conf) |

核心注意点：

1. 这是**常驻 CLI 进程**，不是普通 PHP 站点，必须用 Supervisor / systemd / 进程守护托管
2. 公网访问请走 **Nginx 反向代理**，并开启 WebSocket 支持（`Upgrade` / `Connection` 头）
3. 反代需要透传 `Host`，否则 WebSocket 的 Origin 校验会返回 403

---

## 📖 使用说明

1. 填写主机地址、端口（默认 22）、用户名、密码
2. 勾选「保存此连接」（默认开启），点「连接」进入终端
3. 右侧「最近连接」点记录可一键重连，`×` 删除单条
4. 工具栏支持字号调节、复制全部内容、断开连接
5. 点「文件」进入文件管理器，操作的是**目标 SSH 服务器**上的文件
6. 手机端会自动显示虚拟按键条

### 文件管理器

- 浏览：面包屑导航、上级目录、家目录、刷新、名称过滤、表头排序
- 上传：点击选择或拖拽，分块传输并显示进度
- 下载：分块拉取后由浏览器保存
- 编辑：文本文件在线编辑并保存
- 其他：重命名、移动到、复制到、删除、新建文件 / 目录、修改权限、压缩（tar.gz / zip / tar.bz2）、解压

---

## 📁 目录结构

```
webssh/
├── server.php          # 服务端：HTTP + WebSocket + SSH/SFTP + 记录/文件 API
├── index.html          # 前端：登录表单 + 最近连接 + xterm 终端 + 文件管理器
├── start.sh            # 启动脚本（可选）
├── assets/
│   ├── xterm.js
│   ├── xterm.css
│   └── addon-fit.js
├── docs/
│   ├── deploy-baota.md
│   └── nginx-websocket.conf
├── data/               # 运行时生成：各设备连接记录（已 gitignore）
└── LICENSE
```

---

## 🔐 安全提示

**这个工具本身没有任何访问认证。** 任何能打开页面的人，都能用它去连接任意服务器的 SSH。请务必：

- ❗ **不要直接暴露在公网**。如需公网访问，至少加一层 Nginx Basic Auth 或限制来源 IP
- ❗ **不要用 root 账号做日常连接**，使用普通账号 + sudo
- ❗ **必须启用 HTTPS**，否则密码在传输过程中可被嗅探
- ❗ 警惕 SSH 暴力破解：建议配合 Fail2ban、修改默认端口或改用密钥登录

### 关于连接记录的存储

`data/{设备ID}/connections.json` 中的密码是 **base64 编码，不是加密**。任何能读取该目录的人都能还原明文密码。请：

- 确保 `data/` 目录权限仅运行用户可读
- **不要**把 `data/` 提交到版本库（`.gitignore` 已排除）
- 定期清理不需要的记录

> 本项目定位于**内网 / 个人使用**。公网部署前请自行评估风险。

---

## ⚠️ 已知限制

- 仅支持**密码认证**，暂不支持密钥登录
- 单进程 `stream_select` 事件循环，默认上限 200 个并发客户端（`MAX_CLIENTS`）
- 文件管理不限制路径，可访问目标服务器全盘（仅做路径规范化）
- 终端在极端高负载下可能存在输入延迟

---

## 🤝 贡献

欢迎 Issue 和 PR。提交前请确认：

- 不要提交 `data/`、日志，或任何包含真实服务器信息的配置
- 保持后端单文件结构，不引入 Composer 依赖

---

## 📄 License

[MIT](LICENSE)
