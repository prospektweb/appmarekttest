# Реализация: Страница калькулятора и автоматическая интеграция

## Проблема (решено)

При загрузке iframe с React-калькулятором, приложение отправляло сообщение `READY` через postMessage, но Bitrix не отвечал сообщением `INIT`, потому что класс `ProspektwebCalcIntegration` не создавался автоматически.

## Реализованные изменения

### 1. Создана административная страница калькулятора

**Файл:** `admin/calculator.php`

Страница выполняет следующие функции:
- ✅ Проверяет авторизацию пользователя
- ✅ Проверяет права доступа (`edit_catalog`)
- ✅ Получает `offer_ids` из GET-параметров
- ✅ Валидирует наличие торговых предложений
- ✅ Подключает `integration.js` из `/bitrix/js/prospektweb.calc/`
- ✅ Отображает iframe с React-приложением
- ✅ **Автоматически создаёт экземпляр `ProspektwebCalcIntegration`** с корректными параметрами
- ✅ Включает CSS для полноэкранного отображения
- ✅ Обрабатывает ошибки через локализованные сообщения

### 2. Добавлена локализация

**Файл:** `lang/ru/admin/calculator.php`

Сообщения:
- Заголовок страницы
- Ошибки авторизации
- Ошибки прав доступа
- Ошибка отсутствия модуля
- Ошибка отсутствия торговых предложений
- Общие сообщения об ошибках

### 3. Обновлён процесс установки модуля

**Файл:** `install/index.php`

Изменения в методе `installFiles()`:
- ✅ JS файлы копируются в `/bitrix/js/prospektweb.calc/` (вместо `/local/js/`)
- ✅ CSS файлы копируются в `/bitrix/css/prospektweb.calc/` (вместо `/local/css/`)
- ✅ Tools копируются в `/bitrix/tools/prospektweb.calc/` (вместо `/local/tools/`)
- ✅ Файл `admin/calculator.php` копируется в `/bitrix/admin/prospektweb_calc_calculator.php`
- ✅ React-приложение остаётся в `/local/apps/prospektweb.calc/`

Изменения в методе `uninstallFiles()`:
- ✅ Удаляет файлы из обновлённых директорий
- ✅ Удаляет `/bitrix/admin/prospektweb_calc_calculator.php`

Изменения в методе `checkInstallationIntegrity()`:
- ✅ Проверяет наличие файлов в новых путях
- ✅ Проверяет наличие админского файла калькулятора

### 4. Обновлён путь по умолчанию в integration.js

**Файл:** `install/assets/js/integration.js`

- ✅ Изменён `ajaxEndpoint` по умолчанию с `/local/tools/...` на `/bitrix/tools/...`

### 5. Обновлена документация

**Файл:** `INTEGRATION.md`

- ✅ Обновлены все примеры кода для использования `/bitrix/js/` и `/bitrix/tools/`

## Как использовать

### Доступ к калькулятору

После установки модуля, администратор может открыть калькулятор по адресу:

```
/bitrix/admin/prospektweb_calc_calculator.php?offer_ids=123,456,789
```

Где `123,456,789` — это ID торговых предложений, разделённые запятыми.

### Пример программного открытия

```javascript
// Открыть калькулятор для выбранных товаров
function openCalculator(offerIds) {
    var url = '/bitrix/admin/prospektweb_calc_calculator.php?offer_ids=' + offerIds.join(',');
    window.open(url, '_blank', 'width=1400,height=900');
}

// Пример использования
openCalculator([123, 456, 789]);
```

### Добавление кнопки в админку товаров

В обработчике списка товаров (`OnAdminListDisplay`):

```php
$adminList->AddGroupActionTable([
    'calc' => 'Открыть калькулятор',
]);

// Обработка действия
if ($arID = $adminList->GroupAction()) {
    if ($_REQUEST['action'] === 'calc') {
        $offerIds = implode(',', $arID);
        $url = '/bitrix/admin/prospektweb_calc_calculator.php?offer_ids=' . $offerIds;
        ?>
        <script>
        window.open('<?= $url ?>', '_blank');
        </script>
        <?php
    }
}
```

## Поток работы

1. ✅ Пользователь открывает `/bitrix/admin/prospektweb_calc_calculator.php?offer_ids=123,456`
2. ✅ Страница проверяет авторизацию и права
3. ✅ Загружается iframe с React-приложением из `/local/apps/prospektweb.calc/index.html`
4. ✅ Создаётся экземпляр `ProspektwebCalcIntegration` с параметрами
5. ✅ React отправляет сообщение `READY` через postMessage
6. ✅ `ProspektwebCalcIntegration` получает `READY` и делает AJAX-запрос к `/bitrix/tools/prospektweb.calc/calculator_ajax.php?action=getInitData`
7. ✅ `calculator_ajax.php` возвращает данные инициализации (конфигурации, справочники)
8. ✅ `ProspektwebCalcIntegration` отправляет сообщение `INIT` с данными в iframe
9. ✅ React получает `INIT` и инициализируется с данными
10. ✅ Пользователь работает с калькулятором
11. ✅ При сохранении React отправляет `SAVE_REQUEST`
12. ✅ `ProspektwebCalcIntegration` делает POST-запрос к `calculator_ajax.php`
13. ✅ Результаты сохраняются в Bitrix
14. ✅ React получает `SAVE_RESULT` с подтверждением

## Преимущества реализации

1. **Автоматическая инициализация** — больше не нужно вручную создавать экземпляр класса на каждой странице
2. **Стандартные пути Bitrix** — использование `/bitrix/` директорий вместо `/local/` для системных файлов
3. **Централизованная страница** — единая точка входа для калькуляции
4. **Полноэкранный интерфейс** — оптимизированное отображение для работы с большими формами
5. **Безопасность** — проверка авторизации, прав и CSRF-токенов
6. **Локализация** — все сообщения об ошибках переведены
7. **Простая интеграция** — легко добавить кнопку в любой раздел админки

## Технические детали

### Структура файлов после установки

```
/bitrix/
  admin/
    prospektweb_calc_calculator.php    # Страница калькулятора
  js/
    prospektweb.calc/
      integration.js                    # Класс интеграции
      config.php                        # Конфигурация JS
  css/
    prospektweb.calc/
      calculator.css                    # Стили
  tools/
    prospektweb.calc/
      calculator_ajax.php               # AJAX endpoint
      calculator_config.php
      calculators.php
      elements.php
      equipment.php
      config.php
      calculate.php

/local/
  apps/
    prospektweb.calc/
      index.html                        # React-приложение
      assets/
        index-*.js
        index-*.css
```

### Параметры ProspektwebCalcIntegration

```javascript
new ProspektwebCalcIntegration({
    iframeSelector: '#calc-iframe',      // Селектор iframe
    ajaxEndpoint: '/bitrix/tools/...',   // AJAX endpoint
    offerIds: [123, 456],                // ID торговых предложений
    siteId: 's1',                        // ID сайта
    sessid: 'abc123',                    // Bitrix session ID
    onClose: function() {},              // Обработчик закрытия
    onError: function(error) {}          // Обработчик ошибок
})
```

## Тестирование

Для проверки работы:

1. Установите/переустановите модуль
2. Убедитесь, что файлы скопированы:
   - `/bitrix/admin/prospektweb_calc_calculator.php`
   - `/bitrix/js/prospektweb.calc/integration.js`
   - `/bitrix/tools/prospektweb.calc/calculator_ajax.php`
3. Откройте в браузере:
   ```
   /bitrix/admin/prospektweb_calc_calculator.php?offer_ids=1
   ```
4. Откройте консоль браузера (F12)
5. Проверьте логи:
   - `[Calculator Page] Integration initialized with offer IDs: [1]`
   - `[CalcIntegration] Iframe is ready, fetching init data...`
   - `[CalcIntegration] Sending message: INIT`

## Следующие шаги (опционально)

1. Добавить пункт меню в админку через `OnBuildGlobalMenu`
2. Добавить контекстную кнопку в карточку товара
3. Добавить групповое действие в список товаров
4. Добавить виджет на дашборд админки
