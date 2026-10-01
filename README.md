# OpenCart Module Manager (OCM)

**OCM** — это инструмент командной строки (CLI) для быстрой и удобной разработки модулей OpenCart, созданный по подобию **Laravel Artisan**, но полностью автономный от самого движка OpenCart.

---

## Возможности

- 🚀 **Artisan DX**: понятные неймспейсы команд (`make:module`, `module:dev`, `module:build`, `ocmod:refresh`, `cache:clear`) и короткие привычные алиасы (`dev`, `build`, `install`, `create`, `info`).
- ⚡ **Мгновенная установка через Composer**: глобальный пакет для Linux, macOS и Windows.
- 📦 **Надежная сборка `*.ocmod.zip`**: автоматическая упаковка только нужных файлов (`upload/`, `install.xml`, `install.php`) без мусора и репозиторных файлов.
- 🔄 **Режим Watch (`ocm dev`)**: мгновенная синхронизация изменений в установку OpenCart в реальном времени.
- 🛠️ **Инструменты OpenCart**: автономный сброс модификаторов (`ocm ocmod:refresh`) и очистка системного кэша (`ocm cache:clear`).
- 📋 **Каскадные шаблоны**: поддержка встроенных, глобальных пользовательских (`~/.config/ocm/templates`) и проектных (`./.ocm/templates`) шаблонов.
- 🔗 **Привязка к OpenCart**: простая команда `ocm link /path/to/opencart` без ручного редактирования файлов.

---

## Установка

### 1. Глобальная установка через Composer (Рекомендуется)

```bash
composer global require gregorybiter/ocm-cli
```

> [!TIP]
> Убедитесь, что каталог глобальных бинарников Composer добавлен в переменную `PATH`.
> Для Linux/macOS добавьте в `~/.bashrc` или `~/.zshrc`:
> ```bash
> export PATH="$HOME/.config/composer/vendor/bin:$HOME/.composer/vendor/bin:$PATH"
> ```

Обновление утилиты до последней версии:
```bash
composer global update gregorybiter/ocm-cli
```

---

### 2. Локальная установка в проект модуля

Вы можете установить OCM как dev-зависимость прямо в репозиторий вашего модуля:

```bash
composer require --dev gregorybiter/ocm-cli
```

И запускать через:
```bash
./vendor/bin/ocm list
```

---

### 3. Установка через Shell-скрипт (Legacy)

```bash
curl -sL https://raw.githubusercontent.com/GregoryBiter/ocm-cli/main/install.sh | bash
```

---

## Системные требования

- **PHP** >= 7.4 (поддерживаются PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4)
- **Composer**
- Расширения PHP: `ext-json`, `ext-zip`, `ext-fileinfo` (для строгой валидации XML рекомендуется `ext-dom`)

---

## Использование и Команды

Выполните `ocm list` для просмотра всех доступных команд или `ocm <команда> --help` для подробной справки по опциям.

```
OCM (OpenCart Module Manager) 2.0.0

Доступные команды:
  link            Привязать текущий модуль к директории OpenCart
  status          [info] Показать статус текущего модуля и привязки к OpenCart
  init            Инициализация метаданных модуля и списка файлов (.ocm/files.json)
  migrate         Миграция конфигурации и списков файлов в новый формат .ocm/

 agent
  agent:install   [agent|skills:install|skills] Установка AI Agent скилов и правил (opencart_ai_agent) в OpenCart
  agent:list      [skills:list] Список установленных AI Agent скилов и правил

 cache
  cache:clear     [cc] Очистить системный кэш OpenCart (system/storage/cache)

 db
  db:cli          [db] Открыть интерактивный MySQL терминал к базе OpenCart
  db:dump         [db:export] Создать дамп базы данных OpenCart в SQL файл
  db:import       Импортировать SQL-файл в базу данных OpenCart
  db:info         Показать информацию о подключении к базе данных OpenCart
  db:query        Выполнить SQL-запрос к базе данных OpenCart

 make
  make:module     [create] Создать новый модуль OpenCart из шаблона

 module
  module:build    [build] Сборка готового к распространению архива (*.ocmod.zip)
  module:dev      [dev|watch] Режим наблюдения за изменениями файлов и авто-синхронизация
  module:install  [install] Копирование файлов модуля в установку OpenCart
  module:pull     [return] Возврат файлов из OpenCart в папку модуля (upload/)
  module:remove   [remove] Удаление файлов модуля и модификаций из OpenCart

 ocmod
  ocmod:refresh   Обновить OCMOD модификаторы в связанном OpenCart

 template
  template:list   Список доступных шаблонов модулей OCM
```

---

## Быстрый старт: сценарии работы

### 1. Создание нового модуля

```bash
# Интерактивное создание:
ocm make:module

# Или с указанием параметров в одну команду:
ocm make:module my_super_filter --template=my_module --title="Super Filter" --ver="1.0.0"

# Переходим в созданный модуль:
cd my_super_filter
```

### 2. Привязка к установке OpenCart

```bash
ocm link /var/www/my-opencart.loc
```

### 3. Режим активной разработки

```bash
ocm dev
```
Команда выполнит первичную установку файлов и будет отслеживать изменения в `upload/` и `install.xml`, мгновенно отправляя их в OpenCart.

### 4. Сборка архива для клиентов / маркетплейса

```bash
ocm build
```
Создаст чистый, готовый к загрузке в админку OpenCart архив: `my_super_filter.ocmod.zip` (содержит только `upload/` и `install.xml`).

### 5. Полезные команды для OpenCart

```bash
# Очистить кэш модификаций и перекомпилировать OCMOD:
ocm ocmod:refresh

# Очистить системный кэш OpenCart:
ocm cache:clear

# Посмотреть текущий статус модуля и привязки:
ocm status

# Забрать файлы из OpenCart обратно в модуль (если правили код прямо в магазине):
ocm return
```

### 6. Управление базой данных OpenCart (DB Toolkit)

OCM автоматически считывает реквизиты доступа из `config.php` связанного OpenCart:

```bash
# Проверить соединение и вывести статистику БД:
ocm db:info

# Открыть интерактивный терминал MySQL:
ocm db:cli
# или коротко:
ocm db

# Выполнить произвольный SQL запрос:
ocm db:query "SELECT * FROM oc_setting WHERE \`key\` = 'config_name'"

# Создать полный дамп базы:
ocm db:dump

# Создать сжатый дамп только определенных таблиц:
ocm db:dump backup.sql.gz -z --tables="oc_setting,oc_extension"

# Экспортировать только таблицы с префиксом OpenCart:
ocm db:dump oc_only.sql --prefix-only

# Импортировать SQL-файл в базу:
ocm db:import backup.sql
```

### 7. Установка AI Agent скилов и правил (AI Agent Toolkit)

OCM умеет развертывать специализированные инструкции и скилы для AI-ассистентов (Google Antigravity, GitHub Copilot, Cursor IDE, Claude Code) прямо в рабочий проект OpenCart из репозитория `opencart_ai_agent`:

```bash
# Установить скилы в текущий OpenCart (по умолчанию из https://github.com/GregoryBiter/opencart_ai_agent.git):
ocm agent:install /var/www/my-opencart.loc

# Если вы находитесь в папке модуля, OCM автоматически определит связанный OpenCart:
ocm agent:install

# Указать альтернативный репозиторий или ветку со скилами:
ocm agent:install --repo=https://github.com/username/my-skills.git --branch=main

# Использовать локальную копию репозитория скилов и симлинки (удобно при разработке):
ocm agent:install --source=/path/to/opencart_ai_agent --symlink

# Установить скилы глобально для текущего пользователя (~/.agents/skills и ~/.gemini/antigravity-cli/skills):
ocm agent:install --global

# Посмотреть статус и список установленных скилов:
ocm agent:list
```

При установке настраиваются:
- `AGENTS.md` — глобальные правила контекста разработки (разграничение ядра и `dev-modules/`);
- `.agents/skills/` — скилы для агентов (`opencart3`, `ocmod`, `ocm-cli` и др. из репозитория);
- `.agents/rules/` — правила архитектуры OpenCart;
- `.github/copilot-instructions.md` — инструкции для GitHub Copilot;
- `.cursorrules` и `.cursor/rules/` — правила для Cursor IDE;
- `.claude/skills/` — скилы для Claude Code.

### 8. Развертывание окружения и запуск скриптов (Scripts & LAMP)

OCM поддерживает запуск встроенных и пользовательских скриптов (`./.ocm/scripts/`, `~/.config/ocm/scripts/`, `scripts/`):

```bash
# Быстро развернуть готовый LAMP-сервер для OpenCart (gb-lamp) в текущую папку:
ocm lamp

# Развернуть LAMP в указанную директорию (например, ./tmp или ./lamp-server):
ocm lamp ./tmp

# Посмотреть список всех доступных скриптов и их источники:
ocm script:list
# или коротко:
ocm scripts

# Запустить любой скрипт по имени с передачей аргументов:
ocm script:run lamp
ocm run test
# Также поддерживается прямой вызов по имени:
ocm test
```

---

## Структура проекта модуля

```
my_module/
├── opencart-module.json    # Метаданные модуля (название, код, версия, автор)
├── install.xml             # OCMOD-модификатор (опционально)
├── upload/                 # Файлы модуля для OpenCart
│   ├── admin/
│   │   ├── controller/
│   │   ├── language/
│   │   ├── model/
│   │   └── view/
│   └── catalog/
└── .ocm/                   # Служебная директория OCM (создается автоматически)
    ├── files.json          # Список отслеживаемых файлов
    └── target              # Путь к связанному OpenCart
```

Список отслеживаемых файлов хранится только в `.ocm/files.json`.
Старый `.ocm_files.json` автоматически переносится при чтении списка или командой
`ocm migrate` и удаляется после переноса. Если оба файла существуют, приоритет имеет
`.ocm/files.json`, а устаревший дубликат удаляется.

---

## Шаблоны (Templates)

OCM поддерживает трехуровневую систему шаблонов:
1. **Локальные для проекта**: `./.ocm/templates/`
2. **Пользовательские**: `~/.config/ocm/templates/`
3. **Встроенные**: поставляются вместе с пакетом OCM

### Встроенные шаблоны:
- **`standard`** *(по умолчанию)*: Стандартный модуль OpenCart 3 с админ-панелью (вкладки General и Settings, мультиязычные заголовок и описание с редактором Summernote), контроллером и Twig-шаблоном витрины, переводами `en-gb`, `ru-ru`, `uk-ua`, `install.xml` и `opencart-module.json`.
- **`crud`**: Продвинутый модуль с собственной таблицей БД (нативный OpenCart DB API без внешних библиотек), полной админ-панелью CRUD (список, добавление, редактирование, удаление, пагинация), хуками событий (`setting/event`), контроллером/моделью витрины и мультиязычностью.
- **`ocmod`**: Модуль OCMOD-модификаторов с готовым `install.xml` (примеры хуков витрины и админки), тумблером статуса и кнопкой быстрого сброса кэша модификаций прямо из настроек модуля.

Посмотреть список шаблонов:
```bash
ocm template:list
```

Создать модуль по шаблону:
```bash
# Интерактивный выбор шаблона:
ocm make:module super_seo

# Быстрое создание по конкретному шаблону:
ocm make:module banner_slider --template=standard --title="Баннерный слайдер"
ocm make:module testimonials   --template=crud     --title="Отзывы клиентов"
ocm make:module quick_checkout --template=ocmod    --title="Быстрый заказ OCMOD"
```

---

## Тестирование

```bash
composer test
```

---

## Лицензия

MIT License. См. файл [LICENSE](LICENSE).
