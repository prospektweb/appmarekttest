<?php
/**
 * Страница для встраивания React-калькулятора через iframe
 * Обеспечивает интеграцию через PostMessage API
 */

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_admin_before.php");

\Bitrix\Main\Loader::includeModule('prospektweb.calc');
\Bitrix\Main\Loader::includeModule('iblock');

use Prospektweb\Calc\Config\ConfigManager;

$configManager = new ConfigManager();

// Получить ID инфоблока ТП
$skuIblockId = $configManager->getSkuIblockId();
$productIblockId = $configManager->getProductIblockId();

// Получить выбранные варианты из GET или сессии
$variantIds = [];
if (!empty($_GET['variants'])) {
    $variantIds = array_map('intval', explode(',', $_GET['variants']));
}

// Загрузить данные вариантов
$variantsData = [];
if (!empty($variantIds) && $skuIblockId > 0) {
    $rsElements = \CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => $skuIblockId, 'ID' => $variantIds],
        false,
        false,
        ['ID', 'NAME']
    );
    while ($arElement = $rsElements->Fetch()) {
        $variantsData[] = [
            'id' => (int)$arElement['ID'],
            'name' => $arElement['NAME'],
            'editUrl' => '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=' . $skuIblockId . '&type=catalog&ID=' . $arElement['ID'] . '&lang=' . LANGUAGE_ID,
        ];
    }
}

// Путь к бандлу React-приложения
$calculatorBundlePath = '/local/apps/prospektweb.calc/index.html';

// Путь к API endpoint
$apiEndpoint = '/local/tools/prospektweb.calc/calculator_api.php';

// Конфигурация для передачи в калькулятор
$config = [
    'skuIblockId' => $skuIblockId,
    'productIblockId' => $productIblockId,
    'siteUrl' => (CMain::IsHTTPS() ? 'https://' : 'http://') . SITE_SERVER_NAME,
    'adminUrl' => '/bitrix/admin/',
    'apiEndpoint' => $apiEndpoint,
    'languageId' => LANGUAGE_ID,
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Калькулятор себестоимости</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            width: 100%;
            height: 100%;
            overflow: hidden;
        }
        
        #calculator-frame {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }
    </style>
</head>
<body>
    <iframe id="calculator-frame" src="<?= htmlspecialchars($calculatorBundlePath) ?>"></iframe>
    
    <script>
    /**
     * Класс для интеграции с React-калькулятором через PostMessage API
     */
    class CalculatorIntegration {
        constructor(iframeId, config) {
            this.iframe = document.getElementById(iframeId);
            this.config = config;
            this.variants = config.variants || [];
            this.initializeListener();
        }
        
        /**
         * Инициализация слушателя сообщений от iframe
         */
        initializeListener() {
            window.addEventListener('message', (event) => {
                const payload = event.data;
                if (!payload || !payload.type) return;
                
                console.log('[Bitrix] Received:', payload.type, payload);
                
                switch (payload.type) {
                    case 'INIT':
                        this.onInit();
                        break;
                    case 'VARIANT_SELECT_REQUEST':
                        this.openVariantSelector();
                        break;
                    case 'VARIANT_REMOVE':
                        this.onVariantRemove(payload.data);
                        break;
                    case 'STATE_UPDATE':
                        this.onStateUpdate(payload.data);
                        break;
                }
            });
        }
        
        /**
         * Отправка сообщения в iframe
         */
        sendMessage(type, data) {
            console.log('[Bitrix] Sending:', type, data);
            this.iframe.contentWindow.postMessage({
                type: type,
                data: data,
                timestamp: Date.now()
            }, '*');
        }
        
        /**
         * Обработка инициализации калькулятора
         */
        onInit() {
            // Отправить конфигурацию
            this.sendMessage('CONFIG_RESPONSE', this.config);
            
            // Отправить данные вариантов
            this.sendMessage('VARIANTS_DATA', {
                variants: this.variants
            });
        }
        
        /**
         * Открыть диалог выбора элементов инфоблока
         */
        openVariantSelector() {
            const self = this;
            
            if (!window.BX || !BX.UI || !BX.UI.EntitySelector) {
                console.error('BX.UI.EntitySelector не загружен');
                alert('Не удалось загрузить компонент выбора элементов.\n\nПожалуйста, обновите страницу. Если проблема сохраняется, обратитесь к администратору.');
                return;
            }
            
            // Подготовим выбранные элементы
            const selectedItems = this.variants.map(v => ['iblock-element', v.id]);
            
            const dialog = new BX.UI.EntitySelector.Dialog({
                targetNode: document.body,
                enableSearch: true,
                multiple: true,
                context: 'CALCULATOR_VARIANTS',
                entities: [
                    {
                        id: 'iblock-element',
                        options: {
                            iblockId: this.config.skuIblockId,
                        }
                    }
                ],
                selectedItems: selectedItems,
                events: {
                    'Item:onSelect': function(event) {
                        const item = event.getData().item;
                        const variantId = parseInt(item.getId());
                        const variantName = item.getTitle();
                        
                        // Проверяем, не добавлен ли уже этот вариант
                        if (!self.variants.find(v => v.id === variantId)) {
                            self.variants.push({
                                id: variantId,
                                name: variantName,
                                editUrl: '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=' + 
                                    self.config.skuIblockId + '&type=catalog&ID=' + variantId + 
                                    '&lang=' + self.config.languageId
                            });
                            
                            // Отправить обновлённый список в iframe
                            self.sendMessage('VARIANTS_DATA', {
                                variants: self.variants
                            });
                            
                            // Сохранить в сессию
                            self.saveVariantsToSession();
                        }
                    },
                    'Item:onDeselect': function(event) {
                        const item = event.getData().item;
                        const variantId = parseInt(item.getId());
                        
                        self.variants = self.variants.filter(v => v.id !== variantId);
                        
                        // Отправить обновлённый список в iframe
                        self.sendMessage('VARIANTS_DATA', {
                            variants: self.variants
                        });
                        
                        // Сохранить в сессию
                        self.saveVariantsToSession();
                    }
                }
            });
            
            dialog.show();
        }
        
        /**
         * Обработка удаления варианта
         */
        onVariantRemove(data) {
            if (!data || !data.variantId) return;
            
            this.variants = this.variants.filter(v => v.id !== data.variantId);
            
            // Сохранить в сессию через AJAX
            this.saveVariantsToSession();
            
            console.log('[Bitrix] Variant removed:', data.variantId);
        }
        
        /**
         * Обработка обновления состояния
         */
        onStateUpdate(state) {
            // Сохранить состояние через AJAX
            fetch(this.config.apiEndpoint + '?action=save_state', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(state)
            })
            .then(response => response.json())
            .then(data => {
                console.log('[Bitrix] State saved:', data);
            })
            .catch(error => {
                console.error('[Bitrix] Error saving state:', error);
            });
        }
        
        /**
         * Сохранить список вариантов в сессию
         */
        saveVariantsToSession() {
            const variantIds = this.variants.map(v => v.id).join(',');
            
            // Обновить URL с новыми вариантами
            const url = new URL(window.location.href);
            url.searchParams.set('variants', variantIds);
            window.history.replaceState({}, '', url);
            
            // Сохранить в сессию через AJAX
            fetch(this.config.apiEndpoint + '?action=save_state', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ variants: this.variants })
            })
            .catch(error => {
                console.error('[Bitrix] Error saving variants:', error);
            });
        }
    }
    
    // Инициализация интеграции после загрузки страницы
    document.addEventListener('DOMContentLoaded', function() {
        const config = <?= json_encode($config) ?>;
        const variants = <?= json_encode($variantsData) ?>;
        
        config.variants = variants;
        
        const integration = new CalculatorIntegration('calculator-frame', config);
        
        // Сделать доступным глобально для отладки
        window.calculatorIntegration = integration;
        
        console.log('[Bitrix] Calculator integration initialized', config);
    });
    </script>
</body>
</html>
