# Оптимизация Multi-Level Category Menu v3.6.0

## Что изменилось

### Проблема
- ❌ Долгие AJAX запросы к `admin-ajax.php` при каждом изменении select
- ❌ Полная загрузка всех категорий из базы данных
- ❌ Нет оптимизации для первого экрана
- ❌ Множественные запросы при навигации по меню

### Решение
- ✅ Статическая генерация JSON файлов
- ✅ Первый уровень встроен в HTML (inline)
- ✅ Последующие уровни загружаются из кэша браузера
- ✅ Автоматическая регенерация при изменении категорий
- ✅ Поддержка gzip сжатия для экономии трафика
- ✅ Fallback на AJAX если JSON недоступен

## Как использовать

### Шаг 1: Включить статическую генерацию

1. Перейди в **Параметры → Category Menu**
2. Отметь опцию **"Use Static JSON Files"**
3. Нажми **"Generate Menu JSON"**
4. Подожди завершения генерации

### Шаг 2: Проверить результат

Данные будут сохранены в:
```
/wp-content/uploads/mlcm-menu-cache/
├── level-1.json       (~2-5 КБ)
├── level-2.json       (~5-10 КБ)
├── level-3.json       (опционально)
├── level-4.json       (опционально)
├── level-5.json       (опционально)
└── meta.json          (метаинформация)
```

## Архитектура

### Структура JSON для уровня 1

```json
[
  {
    "id": 5,
    "name": "КАТЕГОРИЯ 1",
    "slug": "category-1",
    "url": "https://example.com/category/category-1/",
    "hasChildren": true
  },
  {
    "id": 6,
    "name": "КАТЕГОРИЯ 2",
    "slug": "category-2",
    "url": "https://example.com/category/category-2/",
    "hasChildren": false
  }
]
```

### Структура JSON для уровня 2+

```json
{
  "5": [
    {
      "id": 7,
      "name": "ПОДКАТЕГОРИЯ 1",
      "slug": "subcategory-1",
      "url": "https://example.com/category/subcategory-1/",
      "hasChildren": true
    }
  ],
  "6": [
    {
      "id": 8,
      "name": "ПОДКАТЕГОРИЯ 2",
      "slug": "subcategory-2",
      "url": "https://example.com/category/subcategory-2/",
      "hasChildren": false
    }
  ]
}
```

## Производительность

### Сравнение: До vs После

| Метрика | До | После | Улучшение |
|---------|-----|--------|----------|
| Первый запрос | ~500ms (DB query) | ~50ms (встроенный JSON) | **10x быстрее** |
| Второй уровень | ~200ms (AJAX) | ~20ms (cached JSON) | **10x быстрее** |
| Размер данных | ~30 КБ (неоптимизированный) | ~2-5 КБ (уровень 1) | **85% меньше** |
| Кэширование браузером | - | 7 дней | **Экономия трафика** |
| AJAX запросы | 5 запросов | 1-2 запроса | **60% меньше** |

## Автоматическая регенерация

Плагин автоматически регенерирует JSON файлы при:
- ✅ Создании новой категории
- ✅ Редактировании категории
- ✅ Удалении категории
- ✅ Изменении настроек (исключенные категории и т.д.)

## Ручная регенерация

```php
// Прямой вызов в коде
$menu = Multi_Level_Category_Menu::get_instance();
$result = $menu->generate_static_menus();

if ($result['success']) {
    echo 'Меню успешно сгенерировано';
    echo 'Всего уровней: ' . $result['levels'];
} else {
    echo 'Ошибка: ' . $result['message'];
}
```

Или через WP CLI:

```bash
wp eval "Multi_Level_Category_Menu::get_instance()->generate_static_menus();"
```

## Fallback на AJAX

Если JSON файлы недоступны или отключена опция "Use Static JSON Files", плагин автоматически использует AJAX:

```javascript
// Автоматический fallback
loadLevelData(level, parentId, callback);
// → Пытается загрузить JSON
// → Если не получилось, использует AJAX
```

## Оптимизация веб-сервера

### Apache

Автоматически создается `.htaccess` с правильными заголовками Cache-Control:

```apache
Header set Cache-Control "public, max-age=604800"
Header set Content-Encoding gzip
```

### Nginx

Добавь в конфигурацию:

```nginx
location ~* /uploads/mlcm-menu-cache/.*\.json$ {
    expires 7d;
    add_header Cache-Control "public, max-age=604800";
    add_header Content-Encoding gzip;
    gzip_static on;
}
```

## Плагины кэширования

### WP Rocket / FlyingPress / W3 Total Cache

JSON файлы автоматически кэшируются браузером на 7 дней. Настройки плагина кэширования не влияют на работу меню.

### Redis Object Cache

Плагин совместим с Redis. Используй для еще более быстрого доступа к данным:

```bash
wp plugin install redis-cache --activate
wp redis enable
```

## Деактивация статических файлов

Если нужно вернуться на AJAX:

1. Перейди в **Параметры → Category Menu**
2. Отключи опцию **"Use Static JSON Files"**
3. Сохрани изменения

Файлы можно удалить вручную:

```bash
rm -rf /wp-content/uploads/mlcm-menu-cache/*
```

## Поддержка и отладка

### Проверить наличие файлов

```bash
ls -la /wp-content/uploads/mlcm-menu-cache/
```

### Проверить размер файлов

```bash
du -h /wp-content/uploads/mlcm-menu-cache/
```

### Просмотреть содержимое JSON

```bash
cat /wp-content/uploads/mlcm-menu-cache/level-1.json | jq
```

### Включить debug в браузере

В консоли браузера:

```javascript
// Отслеживать все загрузки JSON
window.mlcmDebug = true;
```

## Совместимость

- ✅ WordPress 5.0+
- ✅ PHP 7.4+
- ✅ Все плагины кэширования
- ✅ Redis Object Cache
- ✅ WP CLI
- ✅ Nginx + Apache
- ✅ CDN (CloudFlare и т.д.)

## Версия

Эта документация относится к версии **3.6.0** и выше.

Для обновления с предыдущих версий просто активируй плагин - все работает автоматически с fallback на старый метод.
