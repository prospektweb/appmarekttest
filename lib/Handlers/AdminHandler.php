<?php

namespace Prospektweb\Calc\Handlers;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Page\AssetLocation;
use Bitrix\Main\Application;
use Prospektweb\Calc\Services\HeaderTabsService;

/**
 * Обработчик для добавления кнопки/вкладки в админку.
 */
class AdminHandler
{
    /**
     * Обработчик события OnProlog.
     * Добавляет JS для кнопки "Калькуляция" в админку.
     */
    public static function onProlog(): void
    {
        if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
            return;
        }

        // Проверяем, что мы на странице редактирования элемента инфоблока
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        if (strpos($scriptName, '/bitrix/admin/iblock_element_edit.php') !== false) {
            if (self::isHeaderIblockPage()) {
                self::addHeaderTabsAction();
            }

            self::addCalculatorButton();
        }

        // Также добавляем на странице списка элементов (для кнопки в тулбаре)
        if (strpos($scriptName, '/bitrix/admin/iblock_list_admin.php') !== false) {
            self::addCalculatorButton();
            self::addHeaderTabsAction();
        }
    }

    /**
     * Добавляет JS и CSS для кнопки калькуляции.
     */
    protected static function addCalculatorButton(): void
    {
        $asset = Asset::getInstance();
        
        // Безопасное экранирование SITE_ID для JavaScript через JSON
        $siteId = json_encode(SITE_ID, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $asset->addString('<script>BX.message({ SITE_ID: ' . $siteId . ' });</script>', 
            false, \Bitrix\Main\Page\AssetLocation::AFTER_JS_KERNEL);
        
        // Добавляем CSS
        $cssPath = '/local/css/prospektweb.calc/calculator.css';
        if (file_exists(Application::getDocumentRoot() . $cssPath)) {
            $asset->addCss($cssPath);
        }

        // Добавляем integration.js перед calculator.js (для поддержки нового протокола postMessage)
        $jsIntegrationPath = '/local/js/prospektweb.calc/integration.js';
        if (file_exists(Application::getDocumentRoot() . $jsIntegrationPath)) {
            $asset->addJs($jsIntegrationPath);
        }

        // Добавляем JS
        $jsPath = '/local/js/prospektweb.calc/calculator.js';
        if (file_exists(Application::getDocumentRoot() . $jsPath)) {
            $asset->addJs($jsPath);
        }

        // Добавляем встроенный JS для инициализации кнопки
        $asset->addString('<script>
            BX.ready(function() {
                if (typeof window.ProspekwebCalc !== "undefined" && window.ProspekwebCalc.init) {
                    window.ProspekwebCalc.init();
                }
            });
        </script>', false, AssetLocation::AFTER_JS);
    }

    /**
     * Добавляет JS для массового действия "Использовать в калькуляции".
     */
    protected static function addHeaderTabsAction(): void
    {
        $service = new HeaderTabsService();
        $entityMap = $service->getHeaderIblockMap();

        if (empty($entityMap)) {
            return;
        }

        $asset = Asset::getInstance();
        $jsPath = '/local/js/prospektweb.calc/header-tabs-sync.js';

        if (file_exists(Application::getDocumentRoot() . $jsPath)) {
            $asset->addJs($jsPath);
        }

        $config = [
            'ajaxEndpoint' => '/bitrix/tools/prospektweb.calc/calculator_ajax.php',
            'entityMap' => $entityMap,
            'actionValue' => 'calc_use_in_header',
            'messages' => [
                'actionTitle' => 'Использовать в калькуляции',
            ],
            'sessid' => bitrix_sessid(),
        ];

        $asset->addString(
            '<script>window.ProspektwebCalcHeaderTabsConfig = ' .
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) .
            ';</script>',
            false,
            AssetLocation::AFTER_JS
        );
    }

    /**
     * Получает параметры для инициализации калькулятора.
     *
     * @return array
     */
    public static function getCalculatorParams(): array
    {
        if (!Loader::includeModule('prospektweb.calc')) {
            return [];
        }

        return [
            'moduleInstalled' => true,
            'apiEndpoint' => '/local/modules/prospektweb.calc/tools/',
        ];
    }

    /**
     * Обработчик события OnAdminTabControlBegin.
     *
     * @param \CAdminTabControl $tabControl Объект управления вкладками.
     */
    public static function onTabControlBegin(\CAdminTabControl &$tabControl): void
    {
        // Можно добавить вкладку калькулятора при редактировании товара
    }

    /**
     * Обработчик события OnAdminListDisplay.
     *
     * @param \CAdminList $adminList Объект списка.
     */
    public static function onAdminListDisplay(\CAdminList &$adminList): void
    {
        // Можно добавить кнопку массовой калькуляции в список элементов
    }

    /**
     * Проверяет, относится ли текущая страница к поддерживаемым инфоблокам шапки калькуляции.
     */
    protected static function isHeaderIblockPage(): bool
    {
        $service = new HeaderTabsService();
        $iblockMap = $service->getHeaderIblockMap();

        if (empty($iblockMap)) {
            return false;
        }

        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? $_REQUEST['iblock_id'] ?? $_REQUEST['PARENT'] ?? 0);

        return in_array($iblockId, array_map('intval', $iblockMap), true);
    }
}
