# Интеграция React-калькулятора с Bitrix

## Описание

Этот модуль реализует интеграцию между Bitrix и React-приложением калькуляции через postMessage API.

## Структура файлов

### PHP Backend

- `lib/Calculator/InitPayloadService.php` - Сервис подготовки INIT payload для React-приложения
- `lib/Calculator/SaveHandler.php` - Обработчик сохранения данных из React-приложения
- `tools/calculator_ajax.php` - AJAX endpoint для обработки запросов

### JavaScript Frontend

- `install/assets/js/integration.js` - Основной файл интеграции postMessage
- `install/assets/js/config.php` - Конфигурация JS-расширения

## Использование

### 1. Настройка модуля

В админке Bitrix перейдите в настройки модуля и укажите ID инфоблоков на вкладке "Интеграция":

- ID инфоблока материалов
- ID инфоблока операций
- ID инфоблока оборудования
- ID инфоблока деталей
- ID инфоблока калькуляторов
- ID инфоблока конфигураций

### 2. Создание страницы с iframe

```php
<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
$APPLICATION->SetTitle('Калькулятор');

use Bitrix\Main\Page\Asset;

// Подключаем JS интеграции
Asset::getInstance()->addJs('/bitrix/js/prospektweb.calc/integration.js');

// ID торговых предложений для калькуляции
$offerIds = [123, 456, 789]; // Получите из запроса или контекста
?>

<div id="calc-container" style="width: 100%; height: 800px;">
    <iframe 
        id="calc-iframe" 
        src="/local/apps/prospektweb.calc/index.html"
        style="width: 100%; height: 100%; border: none;">
    </iframe>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Инициализация интеграции
    const integration = new ProspektwebCalcIntegration({
        iframeSelector: '#calc-iframe',
        ajaxEndpoint: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
        offerIds: <?= json_encode($offerIds) ?>,
        siteId: '<?= SITE_ID ?>',
        sessid: '<?= bitrix_sessid() ?>',
        onClose: function() {
            // Обработчик закрытия окна
            window.location.href = '/admin/catalog_list.php';
        },
        onError: function(error) {
            // Обработчик ошибок
            console.error('Calc error:', error);
            alert('Ошибка калькулятора: ' + (error.message || 'Неизвестная ошибка'));
        }
    });
});
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
```

### 3. Использование в popup

```javascript
// Открыть калькулятор в popup
function openCalcPopup(offerIds) {
    const popup = new BX.PopupWindow('calc-popup', null, {
        content: '<iframe id="calc-iframe" src="/local/apps/prospektweb.calc/index.html" style="width: 100%; height: 700px; border: none;"></iframe>',
        width: 1200,
        height: 800,
        titleBar: 'Калькулятор',
        closeIcon: true,
        closeByEsc: true,
        overlay: true,
        events: {
            onPopupShow: function() {
                // Инициализация интеграции после открытия popup
                setTimeout(function() {
                    const integration = new ProspektwebCalcIntegration({
                        iframeSelector: '#calc-iframe',
                        ajaxEndpoint: '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
                        offerIds: offerIds,
                        siteId: BX.message('SITE_ID'),
                        sessid: BX.bitrix_sessid(),
                        onClose: function() {
                            popup.close();
                        }
                    });
                }, 100);
            }
        }
    });
    
    popup.show();
}

// Вызов
openCalcPopup([123, 456, 789]);
```

## Протокол postMessage

### Типы сообщений

#### От React к Bitrix:

- **READY** - React-приложение готово к инициализации
- **INIT_DONE** - Инициализация завершена
- **CALC_PREVIEW** - Результаты расчёта (предпросмотр)
- **SAVE_REQUEST** - Запрос на сохранение данных
- **CLOSE_REQUEST** - Запрос на закрытие окна
- **ERROR** - Ошибка в React-приложении

#### От Bitrix к React:

- **INIT** - Начальные данные для инициализации
- **SAVE_RESULT** - Результат сохранения данных
- **ERROR** - Ошибка в Bitrix
- **SELECT_DONE** - Результат выбора элементов (мультивыбор)

### Диалог выбора элементов инфоблока

Интеграция использует стандартный popup `/bitrix/admin/iblock_element_search.php` для выборки элементов с фильтрацией по инфоблоку и типу инфоблока. Диалог открывается в двух сценариях:

- **SELECT_REQUEST** — инициируется из iframe, когда React-приложению нужно выбрать произвольный элемент. Payload сообщения:
  ```json
  {
    "iblockId": 12,          // обязательный ID инфоблока
    "iblockType": "catalog", // необязательный код типа инфоблока, чтобы сразу настроить фильтр
    "lang": "ru"             // необязательный язык интерфейса
  }
  ```
  Эти данные передаются как query-параметры `IBLOCK_ID`, `IBLOCK_TYPE` и `lang` к стандартному popup. В качестве `func_name`/`n` указывается временная JS-функция, которую создаёт `install/assets/js/integration.js`.
- **ADD_OFFER_REQUEST** — вызывается при добавлении торгового предложения. Для построения ссылки на popup используются данные INIT: `initData.iblocks.offers` (ID инфоблока предложений), `initData.iblocksTypes[<ID>]` (код его типа) и `initData.lang` (язык). Структура `initData` формируется AJAX-запросом к `calculator_ajax.php` и описана в разделе «AJAX API».

Во всех случаях открывается стандартный интерфейс поиска/выбора элемента инфоблока с возможностью фильтрации. Окно остаётся открытым, позволяет выбрать несколько элементов подряд, показывает счётчик «Выбрано: N», а окончательное закрытие окна возвращает массив элементов через `SELECT_DONE` (с предзагруженными полными данными).

Формат ответа:
```json
{
  "protocol": "pwrt-v1",
  "version": "1.0.0",
  "source": "bitrix",
  "target": "prospektweb.calc",
  "type": "SELECT_DONE",
  "requestId": "<uuid>",
  "timestamp": 1711111111111,
  "payload": {
    "iblockId": 12,
    "iblockType": "catalog",
    "lang": "ru",
    "items": [
      {
        "id": 1,
        "productId": 100,
        "name": "Элемент",
        "fields": {},
        "measure": null,
        "measureRatio": 1,
        "prices": [],
        "properties": {}
      }
    ]
  }
}
```

### Формат сообщений

```typescript
interface PwrtMessage {
  source: 'prospektweb.calc' | 'bitrix'
  target: 'bitrix' | 'prospektweb.calc'
  type: string
  requestId?: string
  payload?: any
  timestamp?: number
}
```

## AJAX API

### Получение данных инициализации

```
GET /bitrix/tools/prospektweb.calc/calculator_ajax.php?action=getInitData&offerIds=123,456&siteId=s1&sessid=xxx
```

Ответ:
```json
{
  "success": true,
  "data": {
    "mode": "NEW_BUNDLE" | "EXISTING_BUNDLE",
    "context": {
      "siteId": "s1",
      "userId": "1",
      "lang": "ru",
      "timestamp": 1234567890
    },
    "iblocks": {
      "materials": 10,
      "operations": 11,
      "equipment": 12,
      "details": 13,
      "calculators": 14,
      "configurations": 15
    },
    "selectedOffers": [
      {
        "id": 123,
        "productId": 100,
        "name": "Товар 1"
      }
    ],
    "bundle": { // только для EXISTING_BUNDLE
      "id": 50,
      "name": "Сборка для визиток",
      "code": "business_cards_bundle",
      "structure": {},
      "elements": {}
    }
  }
}
```

### Сохранение данных

```
POST /bitrix/tools/prospektweb.calc/calculator_ajax.php
action=save&sessid=xxx&payload={"mode":"NEW_BUNDLE","configuration":{...},"offerUpdates":[...]}
```

Ответ:
```json
{
  "success": true,
  "data": {
    "status": "ok" | "error" | "partial",
    "bundleId": 50,
    "successOffers": [123, 456],
    "errors": [],
    "message": "Данные успешно сохранены"
  }
}
```

## Безопасность

- Все запросы проверяют авторизацию и права доступа
- CSRF-защита через sessid
- Валидация всех входящих данных
- Транзакции при сохранении данных
- Опциональное логирование всех операций

## Логирование

Для включения логирования:
1. Перейдите в настройки модуля
2. Установите флаг "Включить логирование"

Логи сохраняются в:
- `/local/logs/prospektweb.calc.log` - общие операции
- `/local/logs/prospektweb.calc.ajax.log` - AJAX запросы

## Отладка

Для отладки postMessage откройте консоль браузера. Все сообщения логируются с префиксом `[CalcIntegration]`.
