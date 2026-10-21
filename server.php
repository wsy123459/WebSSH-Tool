<?php
/**
 * WebSSH 单文件服务器：HTTP 静态服务 + WebSocket + SSH 转发
 * 启动: php server.php  (默认端口 8080, 可用环境变量 PORT 覆盖)
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('default_socket_timeout', '10');

$HOST = '0.0.0.0';
$PORT = (int) (getenv('PORT') ?: 8080);
$WEBROOT = __DIR__;
$DATA_DIR = $WEBROOT . '/data';
if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0755, true);

$clients = [];
$master = stream_socket_server("tcp://{$HOST}:{$PORT}", $errno, $errstr);
if (!$master) {
    fwrite(STDERR, "无法监听 {$HOST}:{$PORT}: $errstr\n");
    exit(1);
}
stream_set_blocking($master, false);
echo "WebSSH server: http://localhost:{$PORT}\n";

const MAX_FRAME = 1024 * 1024;
const MAX_WBUF = 16 * 1024 * 1024;
const MAX_CLIENTS = 200;
const CLIENT_IDLE_TIMEOUT = 300;
const FS_DL_CHUNK = 262144;

$FS_UPLOADS = [];

function wsEncode(string $payload, int $opcode = 0x1): string
{
    $len = strlen($payload);
    $head = chr(0x80 | $opcode);
    if ($len < 126) {
        $head .= chr($len);
    } elseif ($len < 65536) {
        $head .= chr(126) . pack('n', $len);
    } else {
        $head .= chr(127) . pack('J', $len);
    }
    return $head . $payload;
}

function wsParse(array &$c): ?array
{
    $buf = $c['rbuf'];
    $n = strlen($buf);
    if ($n < 2) return null;
    $b1 = ord($buf[0]);
    $b2 = ord($buf[1]);
    $fin = ($b1 >> 7) & 1;
    $opcode = $b1 & 0x0f;
    $masked = ($b2 >> 7) & 1;
    $len = $b2 & 0x7f;
    $off = 2;
    if ($len === 126) {
        if ($n < $off + 2) return null;
        $len = unpack('n', substr($buf, $off, 2))[1];
        $off += 2;
    } elseif ($len === 127) {
        if ($n < $off + 8) return null;
        $len = unpack('J', substr($buf, $off, 8))[1];
        $off += 8;
    }
    if ($len > MAX_FRAME) {
        $c['frame_too_big'] = true;
        return null;
    }
    $maskKey = '';
    if ($masked) {
        if ($n < $off + 4) return null;
        $maskKey = substr($buf, $off, 4);
        $off += 4;
    }
    if ($n < $off + $len) return null;
    $payload = substr($buf, $off, $len);
    if ($masked) {
        for ($i = 0; $i < $len; $i++) {
            $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
        }
    }
    $c['rbuf'] = substr($buf, $off + $len);
    return ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload];
}

function httpServe(string $method, string $path): string
{
    global $WEBROOT;
    $path = parse_url($path, PHP_URL_PATH) ?: '/';
    $file = null;
    if ($path === '/' || $path === '/index.html') {
        $file = $WEBROOT . '/index.html';
    } elseif (preg_match('#^/assets/([a-zA-Z0-9][a-zA-Z0-9._-]*)$#', $path, $am) && strpos($am[1], '..') === false) {
        $file = $WEBROOT . $path;
    }
    if ($file === null || !is_file($file)) {
        return "HTTP/1.1 404 Not Found\r\nContent-Type: text/plain\r\nConnection: close\r\nContent-Length: 9\r\n\r\nNot Found\n";
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml',
    ];
    $type = $types[$ext] ?? 'application/octet-stream';
    $body = (string) file_get_contents($file);
    return "HTTP/1.1 200 OK\r\nContent-Type: {$type}\r\nContent-Length: " . strlen($body)
        . "\r\nCache-Control: no-cache\r\nConnection: close\r\n\r\n" . ($method === 'GET' ? $body : '');
}

function jsonResp(int $code, array $data): string
{
    $body = json_encode($data, JSON_UNESCAPED_UNICODE);
    return "HTTP/1.1 {$code} " . ($code === 200 ? 'OK' : 'Error')
        . "\r\nContent-Type: application/json; charset=utf-8\r\nContent-Length: " . strlen($body)
        . "\r\nAccess-Control-Allow-Origin: *\r\nConnection: close\r\n\r\n" . $body;
}

function loadConnections(string $file): array
{
    if (!is_file($file)) return [];
    $list = json_decode((string) @file_get_contents($file), true);
    return is_array($list) ? array_values($list) : [];
}

function saveConnections(string $file, array $list): void
{
    $tmp = $file . '.tmp.' . getmypid();
    @file_put_contents($tmp, json_encode(array_values($list), JSON_UNESCAPED_UNICODE));
    @rename($tmp, $file);
}

function apiDispatch(string $method, string $path, string $body): string
{
    global $DATA_DIR;
    $route = $path;
    $query = '';
    $qpos = strpos($path, '?');
    if ($qpos !== false) {
        $query = substr($path, $qpos + 1);
        $route = substr($path, 0, $qpos);
    }
    if ($route !== '/api/connections') {
        return jsonResp(404, ['ok' => false, 'message' => '接口不存在']);
    }
    parse_str($query, $params);
    $did = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($params['d'] ?? ''));
    if (strlen($did) < 8 || strlen($did) > 64) {
        return jsonResp(400, ['ok' => false, 'message' => '无效的设备标识']);
    }
    $dir = $DATA_DIR . '/' . $did;
    $file = $dir . '/connections.json';

    if ($method === 'GET') {
        $list = loadConnections($file);
        foreach ($list as &$item) {
            $item['password'] = (string) base64_decode((string) ($item['password'] ?? ''), true) ?: '';
        }
        unset($item);
        return jsonResp(200, ['ok' => true, 'list' => $list]);
    }

    if ($method === 'POST') {
        $msg = json_decode($body, true);
        $host = trim((string) ($msg['host'] ?? ''));
        $port = max(1, min(65535, (int) ($msg['port'] ?? 22)));
        $user = trim((string) ($msg['username'] ?? ''));
        $pass = (string) ($msg['password'] ?? '');
        $name = trim((string) ($msg['name'] ?? ''));
        if ($host === '' || $user === '') {
            return jsonResp(400, ['ok' => false, 'message' => '主机和用户名不能为空']);
        }
        $cid = md5($host . '|' . $port . '|' . $user);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $list = loadConnections($file);
        $found = false;
        foreach ($list as &$item) {
            if (($item['id'] ?? '') === $cid) {
                $item['password'] = base64_encode($pass);
                $item['last_used'] = time();
                if ($name !== '') $item['name'] = $name;
                $found = true;
                break;
            }
        }
        unset($item);
        if (!$found) {
            $list[] = [
                'id' => $cid,
                'name' => $name !== '' ? $name : $host,
                'host' => $host,
                'port' => $port,
                'username' => $user,
                'password' => base64_encode($pass),
                'last_used' => time(),
            ];
        }
        usort($list, fn($a, $b) => ($b['last_used'] ?? 0) <=> ($a['last_used'] ?? 0));
        saveConnections($file, $list);
        return jsonResp(200, ['ok' => true]);
    }

    if ($method === 'DELETE') {
        $cid = (string) ($params['cid'] ?? '');
        $list = array_values(array_filter(loadConnections($file), fn($x) => ($x['id'] ?? '') !== $cid));
        saveConnections($file, $list);
        return jsonResp(200, ['ok' => true]);
    }

    return jsonResp(405, ['ok' => false, 'message' => '不支持的方法']);
}

function sshDescribeError(string $msg): string
{
    if (stripos($msg, 'auth') !== false) return '用户名或密码错误';
    if (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) return '连接超时，请检查 IP 和端口';
    if (stripos($msg, 'refused') !== false) return '连接被拒绝，目标端口未开放';
    if (stripos($msg, 'resolved') !== false || stripos($msg, 'name or service') !== false) return '主机名无法解析，请检查 IP';
    if (stripos($msg, 'unreachable') !== false) return '网络不可达，请检查 IP';
    if (stripos($msg, 'unable to connect') !== false || stripos($msg, 'banner') !== false || stripos($msg, 'handshake') !== false) {
        return '目标端口未返回 SSH 服务：TCP 可连通，但 ' . '服务器在 SSH 握手前关闭了连接（可能未运行 SSH，或防火墙/安全组拦截了当前来源）';
    }
    return $msg;
}

function sshDiagnose(string $host, int $port): ?string
{
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 10);
    if ($fp === false) {
        $e = strtolower($errstr);
        if (strpos($e, 'getaddrinfo') !== false || strpos($e, 'name or service') !== false || strpos($e, 'no such host') !== false) {
            return '主机名无法解析，请检查 IP 或域名是否正确';
        }
        if (strpos($e, 'timed out') !== false || strpos($e, 'timeout') !== false) {
            return '连接超时：目标 ' . $host . ':' . $port . ' 无响应，请检查 IP、端口及网络是否可达';
        }
        if (strpos($e, 'refused') !== false) {
            return '连接被拒绝：' . $host . ':' . $port . ' 端口未开放或 SSH 服务未启动';
        }
        if (strpos($e, 'unreachable') !== false || strpos($e, 'no route') !== false) {
            return '网络不可达：' . $host . ' 无法路由，请检查 IP 与网络';
        }
        return '无法连接到 ' . $host . ':' . $port . '（' . ($errstr !== '' ? $errstr : '未知错误') . '）';
    }
    fclose($fp);
    return null;
}

function sshOpen(array &$c, array $msg): void
{
    if (!empty($c['ssh'])) {
        @fclose($c['ssh']);
        if (isset($c['ssh_session'])) @ssh2_disconnect($c['ssh_session']);
        $c['ssh'] = null;
        $c['ssh_session'] = null;
    }
    $c['ssh'] = null;
    $host = trim((string) ($msg['host'] ?? ''));
    $port = max(1, min(65535, (int) ($msg['port'] ?? 22)));
    $user = trim((string) ($msg['username'] ?? ''));
    $pass = (string) ($msg['password'] ?? '');
    $fail = function (string $m) use (&$c) {
        wsClientSend($c, ['type' => 'error', 'message' => $m]);
        $c['close_after_flush'] = true;
    };
    if ($host === '' || $user === '' || $pass === '') {
        $fail('请填写完整的主机地址、用户名和密码');
        return;
    }
    $netErr = sshDiagnose($host, $port);
    if ($netErr !== null) {
        $fail($netErr);
        return;
    }
    error_clear_last();
    $session = @ssh2_connect($host, $port);
    if (!$session) {
        $last = error_get_last();
        $raw = is_array($last) ? (string) ($last['message'] ?? '') : '';
        $fail('SSH 握手失败：' . sshDescribeError($raw));
        return;
    }
    error_clear_last();
    if (!@ssh2_auth_password($session, $user, $pass)) {
        @ssh2_disconnect($session);
        $last = error_get_last();
        $raw = is_array($last) ? (string) ($last['message'] ?? '') : '';
        $e = strtolower($raw);
        if (strpos($e, 'auth') !== false || strpos($e, 'password') !== false || strpos($e, 'denied') !== false) {
            $fail('认证失败：用户名或密码错误');
        } elseif (strpos($e, 'publickey') !== false) {
            $fail('认证失败：该服务器要求密钥登录，暂不支持密码登录');
        } else {
            $fail('认证失败：用户名或密码错误');
        }
        return;
    }
    $stream = @ssh2_shell($session, 'xterm-256color', null, 80, 24, SSH2_TERM_UNIT_CHARS);
    if (!$stream) {
        @ssh2_disconnect($session);
        $fail('无法创建 Shell 会话：服务器可能禁止交互式登录');
        return;
    }
    stream_set_blocking($stream, false);
    stream_set_timeout($stream, 0, 100000);
    $c['ssh_session'] = $session;
    $c['ssh'] = $stream;
    $c['fs_host'] = $host;
    $c['fs_port'] = $port;
    $c['fs_user'] = $user;
    $c['fs_pass'] = $pass;
    $c['sftp'] = null;
    $c['sftp_session'] = null;
    $c['state'] = 'ready';
    wsClientSend($c, ['type' => 'opened', 'host' => $host, 'username' => $user]);
    echo "[ssh] {$user}@{$host}:{$port} connected (client #{$c['id']})\n";
}

function fsNormalizePath(string $p): ?string
{
    $p = trim($p);
    if ($p === '') $p = '/';
    if ($p[0] !== '/') $p = '/' . $p;
    $parts = explode('/', $p);
    $out = [];
    foreach ($parts as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') {
            if (count($out) > 0) array_pop($out);
            continue;
        }
        $out[] = $seg;
    }
    return '/' . implode('/', $out);
}

function fsSftp(array &$c)
{
    if (!empty($c['sftp'])) return $c['sftp'];
    if (empty($c['fs_host']) || empty($c['fs_pass'])) { fwrite(STDERR, "[fs] no credentials\n"); return null; }
    error_clear_last();
    $session = @ssh2_connect($c['fs_host'], $c['fs_port']);
    if (!$session) {
        $l = error_get_last();
        fwrite(STDERR, "[fs] connect failed: " . (is_array($l) ? ($l['message'] ?? '') : '') . "\n");
        return null;
    }
    if (!@ssh2_auth_password($session, $c['fs_user'], $c['fs_pass'])) {
        fwrite(STDERR, "[fs] auth failed for sftp session\n");
        @ssh2_disconnect($session);
        return null;
    }
    $sftp = @ssh2_sftp($session);
    if (!$sftp) {
        $l = error_get_last();
        fwrite(STDERR, "[fs] ssh2_sftp failed: " . (is_array($l) ? ($l['message'] ?? '') : '') . "\n");
        @ssh2_disconnect($session);
        return null;
    }
    $c['sftp'] = $sftp;
    $c['sftp_session'] = $session;
    return $sftp;
}

function fsUri(array &$c, string $path): ?string
{
    $sftp = fsSftp($c);
    if (!$sftp) return null;
    return 'ssh2.sftp://' . intval($sftp) . $path;
}

function fsModeString(int $mode): string
{
    $perms = $mode & 0777;
    $s = '';
    $flags = [0400 => 'r', 0200 => 'w', 0100 => 'x', 0040 => 'r', 0020 => 'w', 0010 => 'x', 0004 => 'r', 0002 => 'w', 0001 => 'x'];
    foreach ($flags as $bit => $ch) $s .= ($perms & $bit) ? $ch : '-';
    return $s;
}

function fsRunCommand(array &$c, string $cmd, int $timeoutSec = 30): array
{
    if (fsSftp($c) === null || empty($c['sftp_session'])) return ['ok' => false, 'out' => '', 'err' => 'SSH 会话不可用'];
    $stream = @ssh2_exec($c['sftp_session'], $cmd);
    if (!$stream) return ['ok' => false, 'out' => '', 'err' => '无法执行命令'];
    stream_set_blocking($stream, true);
    stream_set_timeout($stream, $timeoutSec);
    $out = stream_get_contents($stream);
    $errStream = @ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
    $err = $errStream ? stream_get_contents($errStream) : '';
    $meta = stream_get_meta_data($stream);
    @fclose($stream);
    if ($errStream) @fclose($errStream);
    return [
        'ok' => !($meta['timed_out'] ?? false),
        'out' => (string) $out,
        'err' => (string) $err,
        'timeout' => (bool) ($meta['timed_out'] ?? false),
    ];
}

function fsShellQuote(string $s): string
{
    return "'" . str_replace("'", "'\\''", $s) . "'";
}

function fsAction(array &$c, array $msg): void
{
    global $FS_UPLOADS;
    $reqId = (string) ($msg['reqId'] ?? '');
    $action = (string) ($msg['action'] ?? '');
    $reply = function (array $data) use (&$c, $reqId, $action) {
        wsClientSend($c, array_merge(['type' => 'fs', 'reqId' => $reqId, 'action' => $action], $data));
    };
    if (empty($c['ssh_session'])) {
        $reply(['ok' => false, 'message' => '尚未建立 SSH 连接']);
        return;
    }
    clearstatcache();
    $path = isset($msg['path']) ? fsNormalizePath((string) $msg['path']) : '/';

    switch ($action) {
        case 'list': {
            $uri = fsUri($c, $path);
            if ($uri === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            clearstatcache(true, $uri);
            $st = @stat($uri);
            if ($st === false) { $reply(['ok' => false, 'message' => '目录不存在或无法访问']); return; }
            if (($st['mode'] & 0170000) !== 0040000) { $reply(['ok' => false, 'message' => '不是目录']); return; }
            $names = @scandir($uri);
            if ($names === false) { $reply(['ok' => false, 'message' => '读取目录失败（权限不足？）']); return; }
            $entries = [];
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') continue;
                $child = rtrim($path, '/') . '/' . $name;
                $curi = fsUri($c, $child);
                $s = $curi !== null ? @lstat($curi) : false;
                $isLink = $s !== false && (($s['mode'] & 0170000) === 0120000);
                $isDir = $s !== false && (($s['mode'] & 0170000) === 0040000);
                $target = null;
                if ($isLink) {
                    $target = @readlink($curi);
                    if ($target !== false && $target !== '') {
                        $turi = fsUri($c, (substr($target, 0, 1) === '/' ? $target : rtrim($path, '/') . '/' . $target));
                        $ts = $turi !== null ? @stat($turi) : false;
                        if ($ts !== false && (($ts['mode'] & 0170000) === 0040000)) $isDir = true;
                    }
                }
                $entries[] = [
                    'name' => $name,
                    'path' => $child,
                    'dir' => $isDir,
                    'link' => $isLink,
                    'linkTarget' => $target !== false ? $target : null,
                    'size' => $s !== false ? (int) $s['size'] : 0,
                    'mtime' => $s !== false ? (int) $s['mtime'] : 0,
                    'mode' => $s !== false ? (int) ($s['mode'] & 0777) : 0,
                    'modeStr' => $s !== false ? fsModeString((int) $s['mode']) : '---------',
                    'uid' => $s !== false ? (int) $s['uid'] : 0,
                    'gid' => $s !== false ? (int) $s['gid'] : 0,
                ];
            }
            usort($entries, function ($a, $b) {
                if ($a['dir'] !== $b['dir']) return $a['dir'] ? -1 : 1;
                return strcasecmp($a['name'], $b['name']);
            });
            $reply(['ok' => true, 'path' => $path, 'entries' => $entries]);
            return;
        }
        case 'stat': {
            $uri = fsUri($c, $path);
            if ($uri === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            clearstatcache(true, $uri);
            $s = @lstat($uri);
            if ($s === false) { $reply(['ok' => false, 'message' => '文件不存在']); return; }
            $reply(['ok' => true, 'stat' => [
                'path' => $path, 'size' => (int) $s['size'], 'mtime' => (int) $s['mtime'],
                'mode' => (int) ($s['mode'] & 0777), 'modeStr' => fsModeString((int) $s['mode']),
                'dir' => (($s['mode'] & 0170000) === 0040000),
            ]]);
            return;
        }
        case 'read': {
            $uri = fsUri($c, $path);
            if ($uri === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            $s = @stat($uri);
            if ($s === false) { $reply(['ok' => false, 'message' => '文件不存在']); return; }
            if (($s['mode'] & 0170000) !== 0100000) { $reply(['ok' => false, 'message' => '不是普通文件，无法编辑']); return; }
            $max = 2 * 1024 * 1024;
            if ((int) $s['size'] > $max) { $reply(['ok' => false, 'message' => '文件超过 2MB，请下载后编辑', 'toobig' => true, 'size' => (int) $s['size']]); return; }
            $fh = @fopen($uri, 'rb');
            if (!$fh) { $reply(['ok' => false, 'message' => '打开文件失败（权限不足？）']); return; }
            $content = stream_get_contents($fh);
            fclose($fh);
            if ($content === false) { $reply(['ok' => false, 'message' => '读取文件失败']); return; }
            $binary = strpos($content, "\0") !== false;
            $reply(['ok' => true, 'path' => $path, 'size' => strlen($content), 'binary' => $binary, 'content' => $binary ? '' : $content]);
            return;
        }
        case 'write': {
            $uri = fsUri($c, $path);
            if ($uri === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            $content = (string) ($msg['content'] ?? '');
            if (strlen($content) > 2 * 1024 * 1024) { $reply(['ok' => false, 'message' => '内容超过 2MB，无法保存']); return; }
            $fh = @fopen($uri, 'wb');
            if (!$fh) { $reply(['ok' => false, 'message' => '写入失败（权限不足？）']); return; }
            $n = @fwrite($fh, $content);
            fclose($fh);
            if ($n === false) { $reply(['ok' => false, 'message' => '写入失败']); return; }
            $reply(['ok' => true, 'path' => $path, 'size' => $n]);
            return;
        }
        case 'mkdir': {
            $uri = fsUri($c, $path);
            if ($uri === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            if (@is_dir($uri)) { $reply(['ok' => false, 'message' => '目录已存在']); return; }
            if (@mkdir($uri, 0755)) $reply(['ok' => true, 'path' => $path]);
            else $reply(['ok' => false, 'message' => '创建目录失败（权限不足？）']);
            return;
        }
        case 'touch': {
            $uri = fsUri($c, $path);
            if ($uri === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            if (@file_exists($uri)) { $reply(['ok' => false, 'message' => '文件已存在']); return; }
            $fh = @fopen($uri, 'wb');
            if ($fh) { fclose($fh); $reply(['ok' => true, 'path' => $path]); }
            else $reply(['ok' => false, 'message' => '创建文件失败（权限不足？）']);
            return;
        }
        case 'rename':
        case 'move': {
            $to = isset($msg['to']) ? fsNormalizePath((string) $msg['to']) : '';
            if ($to === '') { $reply(['ok' => false, 'message' => '缺少目标路径']); return; }
            $fromUri = fsUri($c, $path);
            $toUri = fsUri($c, $to);
            if ($fromUri === null || $toUri === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            if (!@file_exists($fromUri)) { $reply(['ok' => false, 'message' => '源不存在']); return; }
            if (@file_exists($toUri)) { $reply(['ok' => false, 'message' => '目标已存在']); return; }
            if (@ssh2_sftp_rename($c['sftp'], $path, $to)) $reply(['ok' => true, 'from' => $path, 'to' => $to]);
            else $reply(['ok' => false, 'message' => '重命名/移动失败']);
            return;
        }
        case 'copy': {
            $to = isset($msg['to']) ? fsNormalizePath((string) $msg['to']) : '';
            if ($to === '') { $reply(['ok' => false, 'message' => '缺少目标路径']); return; }
            $fromUri = fsUri($c, $path);
            $toUri = fsUri($c, $to);
            if ($fromUri === null || $toUri === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            $st = @stat($fromUri);
            if ($st === false) { $reply(['ok' => false, 'message' => '源不存在']); return; }
            if (@file_exists($toUri)) { $reply(['ok' => false, 'message' => '目标已存在']); return; }
            if (($st['mode'] & 0170000) === 0040000) {
                $r = fsRunCommand($c, 'cp -a -- ' . fsShellQuote($path) . ' ' . fsShellQuote($to));
                if ($r['ok'] && trim($r['err']) === '') $reply(['ok' => true, 'from' => $path, 'to' => $to]);
                else $reply(['ok' => false, 'message' => '复制目录失败：' . trim($r['err'])]);
                return;
            }
            $in = @fopen($fromUri, 'rb');
            if (!$in) { $reply(['ok' => false, 'message' => '打开源文件失败']); return; }
            $out = @fopen($toUri, 'wb');
            if (!$out) { fclose($in); $reply(['ok' => false, 'message' => '创建目标文件失败']); return; }
            $ok = true;
            while (!feof($in)) {
                $chunk = fread($in, 262144);
                if ($chunk === false) { $ok = false; break; }
                if ($chunk === '') continue;
                if (fwrite($out, $chunk) === false) { $ok = false; break; }
            }
            fclose($in);
            fclose($out);
            if ($ok) $reply(['ok' => true, 'from' => $path, 'to' => $to]);
            else { @unlink($toUri); $reply(['ok' => false, 'message' => '复制失败']); }
            return;
        }
        case 'delete': {
            $paths = isset($msg['paths']) && is_array($msg['paths']) ? $msg['paths'] : [$path];
            $done = [];
            $errors = [];
            foreach ($paths as $p0) {
                $p = fsNormalizePath((string) $p0);
                if ($p === '/' || $p === '') { $errors[] = '/ 不可删除'; continue; }
                $uri = fsUri($c, $p);
                if ($uri === null) { $errors[] = $p . ' SFTP 不可用'; continue; }
                $s = @lstat($uri);
                if ($s === false) { $errors[] = $p . ' 不存在'; continue; }
                $isDir = (($s['mode'] & 0170000) === 0040000);
                if ($isDir) {
                    $r = fsRunCommand($c, 'rm -rf -- ' . fsShellQuote($p));
                    if ($r['ok'] && trim($r['err']) === '') $done[] = $p;
                    else $errors[] = $p . ' ' . trim($r['err']);
                } else {
                    if (@ssh2_sftp_unlink($c['sftp'], $p)) $done[] = $p;
                    else $errors[] = $p . ' 删除失败';
                }
            }
            $reply(['ok' => count($errors) === 0, 'deleted' => $done, 'errors' => $errors]);
            return;
        }
        case 'chmod': {
            $mode = (int) ($msg['mode'] ?? -1);
            if ($mode < 0 || $mode > 07777) { $reply(['ok' => false, 'message' => '权限值非法']); return; }
            $uri = fsUri($c, $path);
            if ($uri === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            if (!@file_exists($uri)) { $reply(['ok' => false, 'message' => '文件不存在']); return; }
            if (@ssh2_sftp_chmod($c['sftp'], $path, $mode)) $reply(['ok' => true, 'path' => $path, 'mode' => $mode]);
            else $reply(['ok' => false, 'message' => '修改权限失败']);
            return;
        }
        case 'extract': {
            $uri = fsUri($c, $path);
            if ($uri === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            if (!@file_exists($uri)) { $reply(['ok' => false, 'message' => '压缩包不存在']); return; }
            $dest = isset($msg['dest']) && $msg['dest'] !== '' ? fsNormalizePath((string) $msg['dest']) : dirname($path);
            $dir = rtrim($dest, '/') === '' ? '/' : rtrim($dest, '/');
            $q = fsShellQuote($path);
            $qd = fsShellQuote($dir);
            $lower = strtolower($path);
            if (preg_match('/\.tar\.(gz|bz2|xz)$/', $lower)) {
                $cmd = 'mkdir -p ' . $qd . ' && tar -xf ' . $q . ' -C ' . $qd;
            } elseif (preg_match('/\.tgz$/', $lower)) {
                $cmd = 'mkdir -p ' . $qd . ' && tar -xzf ' . $q . ' -C ' . $qd;
            } elseif (preg_match('/\.(gz)$/', $lower)) {
                $cmd = 'mkdir -p ' . $qd . ' && gzip -dkf ' . $q;
            } elseif (preg_match('/\.(bz2)$/', $lower)) {
                $cmd = 'mkdir -p ' . $qd . ' && bzip2 -dkf ' . $q;
            } elseif (preg_match('/\.(xz)$/', $lower)) {
                $cmd = 'mkdir -p ' . $qd . ' && xz -dkf ' . $q;
            } elseif (preg_match('/\.(zip)$/', $lower)) {
                $cmd = 'mkdir -p ' . $qd . ' && (command -v unzip >/dev/null 2>&1 && unzip -o ' . $q . ' -d ' . $qd . ' || (command -v 7z >/dev/null 2>&1 && 7z x -y -o' . $qd . ' ' . $q . '))';
            } elseif (preg_match('/\.tar$/', $lower)) {
                $cmd = 'mkdir -p ' . $qd . ' && tar -xf ' . $q . ' -C ' . $qd;
            } else {
                $reply(['ok' => false, 'message' => '不支持的压缩格式']);
                return;
            }
            $r = fsRunCommand($c, $cmd, 120);
            if ($r['timeout']) { $reply(['ok' => false, 'message' => '解压超时']); return; }
            if (trim($r['err']) !== '') { $reply(['ok' => false, 'message' => '解压失败：' . trim($r['err'])]); return; }
            $reply(['ok' => true, 'path' => $path, 'dest' => $dir]);
            return;
        }
        case 'compress': {
            $paths = isset($msg['paths']) && is_array($msg['paths']) ? $msg['paths'] : [$path];
            $fmt = (string) ($msg['format'] ?? 'tar.gz');
            $dest = isset($msg['dest']) && $msg['dest'] !== '' ? fsNormalizePath((string) $msg['dest']) : '';
            if (count($paths) === 0) { $reply(['ok' => false, 'message' => '未选择文件']); return; }
            $norm = [];
            foreach ($paths as $p0) $norm[] = fsNormalizePath((string) $p0);
            if ($dest === '') {
                $base = basename($norm[0]);
                $dest = rtrim(dirname($norm[0]), '/') . '/' . $base . '.' . $fmt;
            }
            $ext = preg_match('/\.(zip)$/i', $dest) ? 'zip' : (preg_match('/\.(tgz)$/i', $dest) ? 'tgz' : (preg_match('/\.(tar\.bz2|tbz2?)$/i', $dest) ? 'tbz' : 'tgz'));
            $qlist = implode(' ', array_map('fsShellQuote', $norm));
            $qd = fsShellQuote($dest);
            if ($ext === 'zip') {
                $cmd = 'cd / && (command -v zip >/dev/null 2>&1 && zip -r ' . $qd . ' ' . $qlist . ' || (command -v 7z >/dev/null 2>&1 && 7z a -y ' . $qd . ' ' . $qlist . '))';
            } elseif ($ext === 'tbz') {
                $cmd = 'cd / && tar -cjf ' . $qd . ' ' . $qlist;
            } else {
                $cmd = 'cd / && tar -czf ' . $qd . ' ' . $qlist;
            }
            $r = fsRunCommand($c, $cmd, 180);
            if ($r['timeout']) { $reply(['ok' => false, 'message' => '压缩超时']); return; }
            $stderr = trim($r['err']);
            $stderr = trim(str_replace('tar: Removing leading `\' from member names', '', $stderr));
            if ($stderr !== '' && !preg_match('/^tar: Removing leading/', $stderr)) {
                $reply(['ok' => false, 'message' => '压缩失败：' . $stderr]);
                return;
            }
            $reply(['ok' => true, 'dest' => $dest]);
            return;
        }
        case 'upload_init': {
            $name = basename($path);
            if ($name === '' || $path === '/' || $name === '.') { $reply(['ok' => false, 'message' => '文件名非法']); return; }
            if (fsSftp($c) === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            $overwrite = !empty($msg['overwrite']);
            if (@ssh2_sftp_stat($c['sftp'], $path) !== false && !$overwrite) {
                $reply(['ok' => false, 'needConfirm' => true, 'message' => '目标文件已存在']);
                return;
            }
            $uri = fsUri($c, $path);
            $fh = @fopen($uri, 'wb');
            if (!$fh) { $reply(['ok' => false, 'message' => '无法创建目标文件（权限不足？）']); return; }
            stream_set_blocking($fh, false);
            $uid = bin2hex(random_bytes(8));
            $FS_UPLOADS[$uid] = ['fh' => $fh, 'path' => $path, 'size' => (int) ($msg['size'] ?? 0), 'written' => 0, 'client' => $c['id']];
            $reply(['ok' => true, 'uploadId' => $uid, 'path' => $path]);
            return;
        }
        case 'upload_chunk': {
            $uid = (string) ($msg['uploadId'] ?? '');
            if (!isset($FS_UPLOADS[$uid])) { $reply(['ok' => false, 'message' => '上传会话已失效']); return; }
            $data = base64_decode((string) ($msg['data'] ?? ''), true);
            if ($data === false) { $reply(['ok' => false, 'message' => '数据解码失败']); return; }
            $fh = $FS_UPLOADS[$uid]['fh'];
            $len = strlen($data);
            $written = 0;
            $deadline = microtime(true) + 30;
            while ($written < $len && microtime(true) < $deadline) {
                $n = @fwrite($fh, substr($data, $written));
                if ($n === false) { $reply(['ok' => false, 'message' => '写入失败（磁盘满或权限不足）']); return; }
                if ($n === 0) { usleep(20000); continue; }
                $written += $n;
            }
            if ($written < $len) { $reply(['ok' => false, 'message' => '写入超时（磁盘满或网络异常）']); return; }
            $FS_UPLOADS[$uid]['written'] += $written;
            $reply(['ok' => true, 'written' => $FS_UPLOADS[$uid]['written']]);
            return;
        }
        case 'upload_finish': {
            $uid = (string) ($msg['uploadId'] ?? '');
            if (!isset($FS_UPLOADS[$uid])) { $reply(['ok' => false, 'message' => '上传会话已失效']); return; }
            $info = $FS_UPLOADS[$uid];
            @fclose($info['fh']);
            unset($FS_UPLOADS[$uid]);
            $reply(['ok' => true, 'path' => $info['path'], 'size' => $info['written']]);
            return;
        }
        case 'download_init': {
            $uri = fsUri($c, $path);
            if ($uri === null) { $reply(['ok' => false, 'message' => 'SFTP 不可用']); return; }
            $s = @stat($uri);
            if ($s === false) { $reply(['ok' => false, 'message' => '文件不存在']); return; }
            if (($s['mode'] & 0170000) === 0040000) { $reply(['ok' => false, 'message' => '目录无法直接下载']); return; }
            $fh = @fopen($uri, 'rb');
            if (!$fh) { $reply(['ok' => false, 'message' => '打开文件失败']); return; }
            stream_set_blocking($fh, false);
            $did = bin2hex(random_bytes(8));
            $FS_UPLOADS[$did] = ['fh' => $fh, 'path' => $path, 'size' => (int) $s['size'], 'read' => 0, 'client' => $c['id'], 'download' => true];
            $reply(['ok' => true, 'downloadId' => $did, 'path' => $path, 'size' => (int) $s['size'], 'name' => basename($path)]);
            return;
        }
        case 'download_chunk': {
            $did = (string) ($msg['downloadId'] ?? '');
            if (!isset($FS_UPLOADS[$did]) || empty($FS_UPLOADS[$did]['download'])) { $reply(['ok' => false, 'message' => '下载会话已失效']); return; }
            $info = &$FS_UPLOADS[$did];
            $deadline = microtime(true) + 20;
            $chunk = '';
            while (microtime(true) < $deadline) {
                $chunk = @fread($info['fh'], FS_DL_CHUNK);
                if ($chunk === false) break;
                if ($chunk !== '' || feof($info['fh'])) break;
                usleep(20000);
            }
            if ($chunk === false) { $reply(['ok' => false, 'message' => '读取失败']); return; }
            $eof = ($chunk === '') ? (bool) feof($info['fh']) : false;
            $info['read'] += strlen($chunk);
            $readNow = $info['read'];
            $sizeNow = $info['size'];
            if ($eof) {
                @fclose($info['fh']);
                unset($FS_UPLOADS[$did]);
            }
            $reply(['ok' => true, 'data' => base64_encode($chunk), 'eof' => $eof, 'read' => $readNow, 'size' => $sizeNow]);
            return;
        }
        case 'download_abort': {
            $did = (string) ($msg['downloadId'] ?? '');
            if (isset($FS_UPLOADS[$did])) {
                @fclose($FS_UPLOADS[$did]['fh']);
                unset($FS_UPLOADS[$did]);
            }
            $reply(['ok' => true]);
            return;
        }
        default:
            $reply(['ok' => false, 'message' => '未知操作：' . $action]);
            return;
    }
}

function wsClientSend(array &$c, array $obj): void
{
    $c['wbuf'] .= wsEncode(json_encode($obj, JSON_UNESCAPED_UNICODE));
}

function wsClose(array &$c): void
{
    global $FS_UPLOADS;
    if (!empty($c['dead'])) return;
    foreach ($FS_UPLOADS as $uid => $info) {
        if (($info['client'] ?? null) === $c['id']) {
            @fclose($info['fh']);
            unset($FS_UPLOADS[$uid]);
        }
    }
    if (!empty($c['ws_established']) && empty($c['close_sent'])) {
        @fwrite($c['sock'], wsEncode('', 0x8));
        $c['close_sent'] = true;
    }
    if (!empty($c['ssh'])) {
        @fclose($c['ssh']);
        if (isset($c['ssh_session'])) {
            @ssh2_disconnect($c['ssh_session']);
        }
        echo "[ssh] client #{$c['id']} session closed\n";
    }
    $c['ssh'] = null;
    $c['ssh_session'] = null;
    if (isset($c['sftp'])) $c['sftp'] = null;
    if (!empty($c['sftp_session'])) {
        @ssh2_disconnect($c['sftp_session']);
        $c['sftp_session'] = null;
    }
    if (isset($c['fs_pass'])) $c['fs_pass'] = null;
    @fclose($c['sock']);
    $c['dead'] = true;
}

$nextId = 1;

while (true) {
    $read = [$master];
    foreach ($clients as $i => $c) {
        if (empty($c['dead'])) $read[] = $c['sock'];
        if (!empty($c['ssh'])) $read[] = $c['ssh'];
    }
    $write = [];
    foreach ($clients as $c) {
        if (empty($c['dead']) && $c['wbuf'] !== '') $write[] = $c['sock'];
    }
    $except = null;
    $n = @stream_select($read, $write, $except, 0, 500000);
    if ($n === false) break;

    if (in_array($master, $read)) {
        while ($sock = @stream_socket_accept($master, 0)) {
            if (count($clients) >= MAX_CLIENTS) {
                @fwrite($sock, "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                @fclose($sock);
                continue;
            }
            stream_set_blocking($sock, false);
            $clients[(int) $sock] = [
                'id' => $nextId++,
                'sock' => $sock,
                'rbuf' => '',
                'wbuf' => '',
                'state' => 'http',
                'ssh' => null,
                'dead' => false,
                'last_recv' => time(),
            ];
        }
    }

    foreach ($write as $sock) {
        $c = &$clients[(int) $sock];
        if (!$c || $c['dead']) { unset($c); continue; }
        $w = @fwrite($sock, $c['wbuf']);
        if ($w === false) {
            wsClose($c);
        } elseif ($w === 0) {
            $c['zero_writes'] = ($c['zero_writes'] ?? 0) + 1;
            if ($c['zero_writes'] > 20) wsClose($c);
        } else {
            $c['zero_writes'] = 0;
            $c['wbuf'] = substr($c['wbuf'], $w);
        }
        unset($c);
        if (!empty($clients[(int) $sock]['dead'])) unset($clients[(int) $sock]);
        elseif (!empty($clients[(int) $sock]['close_after_flush']) && $clients[(int) $sock]['wbuf'] === '') {
            wsClose($clients[(int) $sock]);
            unset($clients[(int) $sock]);
        }
    }

    foreach ($read as $r) {
        if ($r === $master) continue;
        $key = (int) $r;
        $isSsh = false;
        foreach ($clients as &$c) {
            if ($c['ssh'] === $r) {
                $isSsh = true;
                $data = @fread($r, 65536);
                if ($data === false || ($data === '' && feof($r))) {
                    wsClientSend($c, ['type' => 'closed', 'message' => 'SSH 会话已结束']);
                    $c['close_after_flush'] = true;
                } elseif ($data !== '') {
                    $c['last_recv'] = time();
                    wsClientSend($c, ['type' => 'data', 'data' => base64_encode($data)]);
                }
                break;
            }
        }
        unset($c);
        if ($isSsh) continue;

        if (!isset($clients[$key]) || $clients[$key]['sock'] !== $r) continue;
        $c = &$clients[$key];
        if ($c['dead']) { unset($c); continue; }

        $data = @fread($r, 65536);
        if ($data === false || ($data === '' && feof($r))) {
            wsClose($c);
            unset($c, $clients[$key]);
            continue;
        }
        if ($data !== '') $c['last_recv'] = time();
        $c['rbuf'] .= $data;

        if ($c['state'] === 'http') {
            if (empty($c['hdr_end'])) {
                $p = strpos($c['rbuf'], "\r\n\r\n");
                if ($p === false) {
                    if (strlen($c['rbuf']) > 16384) {
                        @fclose($r);
                        $c['dead'] = true;
                    }
                    unset($c);
                    continue;
                }
                $req = substr($c['rbuf'], 0, $p);
                $lines = explode("\r\n", $req);
                [$method, $path] = array_pad(explode(' ', $lines[0] ?? ' '), 2, '');
                $headers = [];
                for ($i = 1; $i < count($lines); $i++) {
                    $cp = strpos($lines[$i], ':');
                    if ($cp !== false) {
                        $headers[strtolower(trim(substr($lines[$i], 0, $cp)))] = trim(substr($lines[$i], $cp + 1));
                    }
                }
                $c['hdr_end'] = $p + 4;
                $c['method'] = strtoupper(trim($method));
                $c['path'] = trim($path);
                $c['headers'] = $headers;
                $c['body_need'] = (int) ($headers['content-length'] ?? 0);
            }
            if (strlen($c['rbuf']) - $c['hdr_end'] < $c['body_need']) {
                if (strlen($c['rbuf']) > 1048576) {
                    @fclose($r);
                    $c['dead'] = true;
                    unset($c, $clients[$key]);
                }
                unset($c);
                continue;
            }
            $body = $c['body_need'] > 0 ? substr($c['rbuf'], $c['hdr_end'], $c['body_need']) : '';
            $isWs = ($c['headers']['upgrade'] ?? '') === 'websocket' && rtrim($c['path'], '/') === '/ws';
            if ($isWs) {
                $origin = $c['headers']['origin'] ?? '';
                if ($origin !== '') {
                    $op = parse_url($origin, PHP_URL_HOST);
                    $hp = parse_url('http://' . ($c['headers']['host'] ?? ''), PHP_URL_HOST);
                    if ($op === null || $hp === null || strcasecmp((string) $op, (string) $hp) !== 0) {
                        $c['wbuf'] .= "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
                        $c['close_after_flush'] = true;
                        unset($c);
                        continue;
                    }
                }
                $key2 = $c['headers']['sec-websocket-key'] ?? '';
                $accept = base64_encode(sha1($key2 . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                fwrite($r, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
                $c['state'] = 'ws';
                $c['ws_established'] = true;
                $c['rbuf'] = substr($c['rbuf'], $c['hdr_end']);
                echo "[ws] client #{$c['id']} connected\n";
            } else {
                if (substr($c['path'], 0, 5) === '/api/' || rtrim($c['path'], '/') === '/api') {
                    $c['wbuf'] .= apiDispatch($c['method'], $c['path'], $body);
                } elseif ($c['method'] === 'GET' || $c['method'] === 'HEAD') {
                    $c['wbuf'] .= httpServe($c['method'], $c['path']);
                } else {
                    $c['wbuf'] .= "HTTP/1.1 405 Method Not Allowed\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
                }
                $c['close_after_flush'] = true;
            }
            if (!empty($c['dead'])) {
                unset($c, $clients[$key]);
                continue;
            }
            unset($c);
            continue;
        }

        while (empty($c['frame_too_big']) && ($frame = wsParse($c)) !== null) {
            $op = $frame['opcode'];
            $pl = $frame['payload'];
            if ($op === 0x8) {
                wsClose($c);
                break;
            } elseif ($op === 0x9) {
                $c['wbuf'] .= wsEncode($pl, 0xA);
                continue;
            } elseif ($op === 0xA) {
                continue;
            } elseif ($op === 0x0) {
                if (($c['frag_op'] ?? null) !== null) {
                    if (strlen($c['frag']) + strlen($pl) > MAX_FRAME) {
                        $c['frame_too_big'] = true;
                        break;
                    }
                    $c['frag'] = ($c['frag'] ?? '') . $pl;
                    if ($frame['fin']) {
                        $op = $c['frag_op'];
                        $pl = $c['frag'];
                        $c['frag_op'] = null;
                        $c['frag'] = '';
                    } else {
                        continue;
                    }
                } else {
                    continue;
                }
            } elseif ($op === 0x1 || $op === 0x2) {
                if (!$frame['fin']) {
                    $c['frag_op'] = $op;
                    $c['frag'] = $pl;
                    continue;
                }
            } else {
                continue;
            }
            $msg = json_decode($pl, true);
            if (!is_array($msg)) continue;
            $type = $msg['type'] ?? '';
            if ($type === 'auth') {
                wsClientSend($c, ['type' => 'status', 'message' => '正在连接 ' . ($msg['host'] ?? '') . ' ...']);
                sshOpen($c, $msg);
                if (!empty($c['dead'])) break;
            } elseif ($type === 'data' && !empty($c['ssh'])) {
                $raw = base64_decode((string) $msg['data']);
                $len = strlen($raw);
                $written = 0;
                while ($written < $len) {
                    $n = @fwrite($c['ssh'], substr($raw, $written));
                    if ($n === false || $n === 0) break;
                    $written += $n;
                }
            } elseif ($type === 'resize' && !empty($c['ssh'])) {
                $cols = max(2, min(500, (int) ($msg['cols'] ?? 80)));
                $rows = max(2, min(300, (int) ($msg['rows'] ?? 24)));
                @ssh2_shell_resize($c['ssh'], $cols, $rows);
            } elseif ($type === 'ping') {
                wsClientSend($c, ['type' => 'pong']);
            } elseif ($type === 'fs') {
                fsAction($c, $msg);
            }
        }
        if (!empty($c['frame_too_big'])) {
            wsClientSend($c, ['type' => 'error', 'message' => '数据帧过大，连接已关闭']);
            wsClose($c);
        }
        if (!empty($c['dead'])) {
            unset($c, $clients[$key]);
            continue;
        }
        unset($c);
    }

    foreach ($clients as $i => $c) {
        if (!empty($c['dead'])) {
            unset($clients[$i]);
            continue;
        }
        if (time() - ($c['last_recv'] ?? 0) > CLIENT_IDLE_TIMEOUT) {
            wsClose($clients[$i]);
            unset($clients[$i]);
            continue;
        }
        if (strlen($c['wbuf']) > MAX_WBUF) {
            wsClose($clients[$i]);
            unset($clients[$i]);
        }
    }
}
