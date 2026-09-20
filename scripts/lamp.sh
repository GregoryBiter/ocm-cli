#!/bin/bash

# ==============================================================================
# GB-LAMP Installer
# Установка и подключение Docker-окружения LAMP (gb-lamp) для OpenCart
# Репозиторий: https://github.com/GregoryBiter/gb-lamp
# Запуск: curl -sSL https://raw.githubusercontent.com/GregoryBiter/gb-lamp/main/lamp.sh | bash
# ==============================================================================

set -e

TARGET_DIR="${1:-.}"
SCRIPT_URL="https://raw.githubusercontent.com/GregoryBiter/gb-lamp/main/lamp.sh"

if command -v curl >/dev/null 2>&1; then
    curl -sSL "$SCRIPT_URL" | bash -s -- "$TARGET_DIR"
elif command -v wget >/dev/null 2>&1; then
    wget -qO- "$SCRIPT_URL" | bash -s -- "$TARGET_DIR"
else
    echo "Ошибка: curl или wget не найдены. Установите curl или wget." >&2
    exit 1
fi
