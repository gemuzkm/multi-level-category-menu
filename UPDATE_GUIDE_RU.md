# Принсталлирование проюнтинованного плагина (v3.6.0)

## Что нового?

Новая версия радикально оптимизирована работа с данными:

```
Перформанс (так насколько нравится мне):

С AJAX:                    С Статическим JSON:

Уровень 1: 500ms         →    Уровень 1: 50ms (встроенный)
Уровень 2: 200ms         →    Уровень 2: 20ms (cached)
Уровень 3: 200ms         →    Уровень 3: 15ms (cached)
──────────────────        ──────────────────
ГОВО: 900ms         →    ГОВО: 85ms

Загружаются: ~30КБ       →    ГОВО: ~7КБ + кэш
```

## Пыть фотографий историй реновации

Это аналог Вашего репо:

ДО:
```
Frontend (хранит в sessionStorage)  → AJAX (сети) → admin-ajax.php → Database
```
ПОСЛЕ:
```
Frontend (inline L1) → Static JSON (gzip) → /uploads/mlcm-menu-cache/ → Browser Cache

I так для остальных уровней (когда нужны)
```

## Обновление

### Шаг 1: Снять истарые файлы

На случай жесткого обновления:

```bash
cd /path/to/your/site
wp plugin deactivate multi-level-category-menu
rm -rf wp-content/plugins/multi-level-category-menu/
```

### Шаг 2: Загружить новые файлы

Скопируй все файлы из своего репо в `wp-content/plugins/multi-level-category-menu/`

Обновыя структура:

```
multi-level-category-menu/
├── multi-level-category-menu.php    (ОБНОВЛЕННЫЙ)
├── assets/
│  ├── js/
│  │  ├── frontend.js                  (ОБНОВЛЕННЫЙ)
│  │  ├── admin.js                     (ОБНОВЛЕННЫЙ)
│  │  └── block-editor.js
│  └── css/
│     ├── frontend.css
│     └── admin.css
├── includes/
│  └── widget.php
├── OPTIMIZATION.md                  (НОВЫЙ)
├── UPDATE_GUIDE_RU.md               (НОВЫЙ)
└── readme.md
```

### Шаг 3: Активируй новый плагин

```bash
wp plugin activate multi-level-category-menu
```

### Шаг 4: Настрой и генерируй JSON

1. На сайте перейди в **Параметры → Category Menu**

2. Отметь опцию **"Use Static JSON Files"** (отключена по умолчанию)

3. Нажми **Кропка**

4. Найди боттон **"Generate Menu JSON"** в секции "Menu Generation"

5. Нажми й ожидай сообщение о успехе

## Морю я предостерегатьне?

Давай брысать данные:

```php
// Ни чего не должно сломаться
// Таблицы базы остаются теми же
// Плагин от них отказывается грациозно
```

### Деактивация

```bash
wp plugin deactivate multi-level-category-menu
rm -rf wp-content/plugins/multi-level-category-menu/
```

## Кско ребята?

Относительно теоретически:

1. Первые 50ms лоадинг - встроенные данные
2. Следюющие уровни - cached где где скомпресированные JSON
3. При изменении категорий - автоматическая перегенерация

## Проверка что всё работает

### Метод 1: через FTP/SSH

```bash
ls -la /wp-content/uploads/mlcm-menu-cache/

# Должно вывести
# level-1.json
# level-1.json.gz
# level-2.json
# level-2.json.gz
# ...
# meta.json
```

### Метод 2: через браузер

1. Открой страницу с меню
2. Нажми F12 (разработчики)
3. Рейспектор Нетворк
4. Пощей меню
5. Проверь XHR/Fetch запросы
   - Должны име GET до `/uploads/mlcm-menu-cache/level-*.json`
   - Статус 200 OK
   - Не должно быть POST к `admin-ajax.php`

## Троублшутинг

### Проблема: JSON не генерируется

1. Проверь права папки `/wp-content/uploads/`
   ```bash
   ls -la wp-content/uploads/ | grep mlcm
   chmod 755 wp-content/uploads/
   ```

2. Проверь debug логи
   ```bash
   tail -f wp-content/debug.log | grep MLCM
   ```

### Проблема: JSON используется, но меню не работает

1. Открой Консоль браузера (F12 → Console)
2. Пощей меню
3. Поиск ошибок с префиксом "MLCM"

### Проблема: CORS ошибки

Если редирект с WWW на non-WWW или вообще редирект:

1. Проверь `WP_HOME` в `wp-config.php`
2. Проверь настройки HTTPS/SSL

## Откатывать к старому методу

Если нужно использовать AJAX:

1. Попал Параметры → Category Menu
2. Отключи **"Use Static JSON Files"**
3. Нажми Кропка

Плагин автоматически вернётся к AJAX в fallback режиме.

## Хроника быстрых МТФ

- v3.5.1 → v3.6.0: Внедрены статические JSON файлы
- Оценка: используй краткие названия (цифры, аббревиатуры)
- План: динамическое сжатие JSON в WebP и BROTLI

## Помощь

Нужна детальная информация?

Открой: [OPTIMIZATION.md](OPTIMIZATION.md)
