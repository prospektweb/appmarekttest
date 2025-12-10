# PostMessage Integration - Битрикс ↔ React

Эта документация описывает реализацию взаимодействия между Битрикс (родительское окно) и React-приложением (iframe) через postMessage API.

## Архитектура

### Битрикс-сторона

**Файл:** `install/assets/js/calculator.js`

#### Новые методы:

1. **`getSelectedOffers()`** - Собирает информацию о выбранных торговых предложениях:
   - Извлекает ID, название и другие данные из DOM
   - Формирует URL для редактирования в админке
   - Возвращает массив объектов с полной информацией

2. **Обновленный `openCalculatorDialog()`** - Использует `getSelectedOffers()` для передачи расширенных данных в iframe

3. **Расширенный `handleMessage()`** - Обрабатывает новые типы сообщений:
   - `CALC_OPEN_OFFER` - Открывает ТП в новой вкладке
   - `CALC_REMOVE_OFFER` - Логирует удаление ТП из списка

### React-сторона

**Директория:** `install/apps_dist/src/`

#### Компоненты:

1. **`hooks/useBitrixBridge.ts`** - Хук для связи с родительским окном:
   ```typescript
   const {
       isReady,      // Готовность к работе
       offers,       // Массив торговых предложений
       apiBase,      // Базовый URL API
       productId,    // ID продукта
       iblockId,     // ID инфоблока
       sessid,       // Идентификатор сессии
       openOffer,    // Функция открытия ТП в новой вкладке
       removeOffer,  // Функция удаления ТП из списка
       closeDialog,  // Функция закрытия диалога
       sendToParent  // Функция отправки сообщений родителю
   } = useBitrixBridge();
   ```

2. **`components/OffersList.tsx`** - Компонент списка ТП:
   ```tsx
   <OffersList
       offers={offers}
       onOpenOffer={openOffer}
       onRemoveOffer={removeOffer}
   />
   ```

3. **`ExampleApp.tsx`** - Пример использования интеграции

## Протокол сообщений

### Битрикс → React (iframe)

#### `BITRIX_INIT`
Инициализация приложения. Отправляется при загрузке iframe.

```javascript
{
    type: 'BITRIX_INIT',
    payload: {
        offers: [
            {
                id: number,           // ID торгового предложения
                name: string,         // Название ТП
                editUrl: string,      // URL для редактирования
                productId: number,    // ID родительского продукта
                iblockId: number      // ID инфоблока
            }
        ],
        apiBase: string,    // Базовый URL для API запросов
        productId: number,  // ID продукта
        iblockId: number,   // ID инфоблока
        sessid: string      // Идентификатор сессии
    }
}
```

### React (iframe) → Битрикс

#### `CALC_READY`
Подтверждение готовности приложения.

```javascript
{
    type: 'CALC_READY'
}
```

#### `CALC_OPEN_OFFER`
Запрос на открытие ТП в новой вкладке.

```javascript
{
    type: 'CALC_OPEN_OFFER',
    payload: {
        editUrl: string,  // URL для открытия
        id: number        // ID торгового предложения
    }
}
```

#### `CALC_REMOVE_OFFER`
Уведомление об удалении ТП из списка.

```javascript
{
    type: 'CALC_REMOVE_OFFER',
    payload: {
        id: number  // ID удаленного торгового предложения
    }
}
```

#### `CALC_CLOSE`
Запрос на закрытие диалога.

```javascript
{
    type: 'CALC_CLOSE'
}
```

## Использование

### В React-приложении

```tsx
import React from 'react';
import { useBitrixBridge } from './hooks/useBitrixBridge';
import { OffersList } from './components/OffersList';

export const App: React.FC = () => {
    const { 
        isReady, 
        offers, 
        openOffer, 
        removeOffer, 
        closeDialog 
    } = useBitrixBridge();

    if (!isReady) {
        return <div>Загрузка...</div>;
    }

    return (
        <div>
            <h1>Калькулятор себестоимости</h1>
            <OffersList
                offers={offers}
                onOpenOffer={openOffer}
                onRemoveOffer={removeOffer}
            />
            <button onClick={closeDialog}>Закрыть</button>
        </div>
    );
};
```

### В Битрикс

Интеграция работает автоматически при вызове:

```javascript
ProspekwebCalc.init();
```

Кнопка "Калькуляция" появляется автоматически на странице редактирования товара с торговыми предложениями.

## Безопасность

- Все сообщения проверяются по origin (должен совпадать с `window.location.origin`)
- `sessid` добавляется автоматически на стороне Битрикс
- URL для редактирования формируются на стороне Битрикс

## Важные замечания

1. **Модуль называется `prospektweb.calc`** (16 символов, без пробела после точки)
2. **НЕ использовать `prospektweb. calc`** (с пробелом) - это ошибка!
3. Все TypeScript типы экспортированы и могут быть импортированы для использования в других компонентах
4. Компонент `OffersList` использует Tailwind CSS классы (убедитесь, что они доступны)

## Тестирование

Для тестирования интеграции:

1. Откройте страницу редактирования товара с торговыми предложениями в Битрикс
2. Выберите несколько торговых предложений
3. Нажмите кнопку "Калькуляция"
4. В открывшемся диалоге должен отобразиться список выбранных ТП
5. Попробуйте открыть ТП в новой вкладке (иконка со стрелкой)
6. Попробуйте удалить ТП из списка (иконка X)

## Расширение функционала

Для добавления новых типов сообщений:

1. Добавьте обработчик в `handleMessage()` в `calculator.js`
2. Добавьте соответствующий case в `useEffect` хука `useBitrixBridge`
3. При необходимости добавьте новый метод в интерфейс хука

## Структура файлов

```
install/
├── assets/
│   └── js/
│       └── calculator.js          # Битрикс-сторона интеграции
└── apps_dist/
    ├── index.html                 # Точка входа React-приложения
    └── src/                       # Исходные файлы React
        ├── hooks/
        │   └── useBitrixBridge.ts # Хук для взаимодействия с Битрикс
        ├── components/
        │   └── OffersList.tsx     # Компонент списка ТП
        └── ExampleApp.tsx         # Пример использования
```
