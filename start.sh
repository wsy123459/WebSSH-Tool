#!/usr/bin/env bash
# WebSSH 启动脚本
#
#   ./start.sh                                      默认 8080 端口
#   PORT=9000 ./start.sh                            自定义端口
#   PHP_BIN=/www/server/php/81/bin/php ./start.sh   指定 PHP 可执行文件

set -e
cd "$(dirname "$0")" || exit 1
exec "${PHP_BIN:-php}" server.php
