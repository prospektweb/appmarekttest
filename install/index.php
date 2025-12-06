<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Application;

Loc::loadMessages(__FILE__);

class prospektweb_calc extends CModule
{
    public $MODULE_ID = 'prospektweb.calc';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    // ВАЖНО для Marketplace: объявляем без значения
    public $PARTNER_NAME;
    public $PARTNER_URI;
    public $MODULE_GROUP_RIGHTS = 'Y';

    /** @var string Путь к модулю */
    protected $modulePath;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = $arModuleVersion['VERSION'] ?? '1.0.0';
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'] ?? date('Y-m-d');

        $this->MODULE_NAME = Loc::getMessage('PROSPEKTWEB_CALC_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('PROSPEKTWEB_CALC_MODULE_DESC');

        // ВАЖНО для Marketplace: явное присваивание через $this->
        $this->PARTNER_NAME = "PROSPEKT-WEB";
        $this->PARTNER_URI = "https://prospekt-web.ru";

        $this->modulePath = dirname(__DIR__);
    }

    public function DoInstall()
    {
        global $APPLICATION;
        
        if (!$this->checkDependencies()) {
            return false;
        }
        
        // Определяем текущий шаг установки
        $step = (int)($_REQUEST['step'] ?? 1);
        
        switch ($step) {
            case 1:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_TITLE'), 
                    __DIR__ . '/step1.php'
                );
                break;
                
            case 2:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_TITLE'), 
                    __DIR__ . '/step2.php'
                );
                break;
                
            case 3:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_TITLE'), 
                    __DIR__ . '/step3.php'
                );
                break;
                
            case 4:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_TITLE'), 
                    __DIR__ . '/step4.php'
                );
                break;
                
            default:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_CALC_INSTALL_TITLE'), 
                    __DIR__ . '/step1.php'
                );
        }
        
        return true;
    }

    public function DoUninstall()
    {
        global $APPLICATION;
        $APPLICATION->IncludeAdminFile(Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_TITLE'), __DIR__ . '/unstep1.php');
    }

    private function checkDependencies(): bool
    {
        if (!ModuleManager::isModuleInstalled('iblock')) {
            $GLOBALS['APPLICATION']->ThrowException(Loc::getMessage('PROSPEKTWEB_CALC_IBLOCK_REQUIRED'));
            return false;
        }
        if (!ModuleManager::isModuleInstalled('catalog')) {
            $GLOBALS['APPLICATION']->ThrowException(Loc::getMessage('PROSPEKTWEB_CALC_CATALOG_REQUIRED'));
            return false;
        }
        return true;
    }

    public function installFiles(): bool
    {
        $docRoot = Application::getDocumentRoot();
        
        // Путь к assets относительно install директории
        $sourceJs = __DIR__ . '/assets/js';
        $sourceCss = __DIR__ . '/assets/css';
        
        $targetJs = $docRoot . '/local/js/prospektweb.calc';
        $targetCss = $docRoot . '/local/css/prospektweb.calc';
        
        $success = true;
        
        // Проверяем существование исходных директорий
        if (is_dir($sourceJs)) {
            CopyDirFiles($sourceJs, $targetJs, true, true);
        } else {
            $success = false;
        }
        
        if (is_dir($sourceCss)) {
            CopyDirFiles($sourceCss, $targetCss, true, true);
        } else {
            $success = false;
        }
        
        return $success;
    }

    public function uninstallFiles(): void
    {
        $jsDir = Application::getDocumentRoot() . '/local/js/prospektweb.calc';
        $cssDir = Application::getDocumentRoot() . '/local/css/prospektweb.calc';

        if (is_dir($jsDir)) {
            DeleteDirFilesEx('/local/js/prospektweb.calc');
        }
        if (is_dir($cssDir)) {
            DeleteDirFilesEx('/local/css/prospektweb.calc');
        }
    }

    public function installEvents(): void
    {
        $em = EventManager::getInstance();
        $em->registerEventHandler(
            'main',
            'OnAdminTabControlBegin',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onTabControlBegin'
        );
        $em->registerEventHandler(
            'main',
            'OnAdminListDisplay',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onAdminListDisplay'
        );
        $em->registerEventHandler(
            'iblock',
            'OnAfterIBlockElementUpdate',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\DependencyHandler',
            'onElementUpdate'
        );
    }

    public function uninstallEvents(): void
    {
        $eventManager = EventManager::getInstance();
        
        $eventManager->unRegisterEventHandler(
            'main',
            'OnAdminTabControlBegin',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onTabControlBegin'
        );
        
        $eventManager->unRegisterEventHandler(
            'main',
            'OnAdminListDisplay',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\AdminHandler',
            'onAdminListDisplay'
        );
        
        $eventManager->unRegisterEventHandler(
            'iblock',
            'OnAfterIBlockElementUpdate',
            $this->MODULE_ID,
            '\\Prospektweb\\Calc\\Handlers\\DependencyHandler',
            'onElementUpdate'
        );
    }

    /**
     * Удаление инфоблоков модуля
     */
    public function deleteIblocks(): void
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return;
        }

        $iblockCodes = [
            'CALC_CONFIG',
            'CALC_SETTINGS',
            'CALC_MATERIALS',
            'CALC_MATERIALS_VARIANTS',
            'CALC_WORKS',
            'CALC_WORKS_VARIANTS',
            'CALC_EQUIPMENT',
            'CALC_DETAILS',
            'CALC_DETAILS_VARIANTS',
        ];

        foreach ($iblockCodes as $code) {
            $iblockId = Option::get($this->MODULE_ID, 'IBLOCK_' . $code, 0);

            if ((int)$iblockId > 0) {
                \CIBlock::Delete((int)$iblockId);
            }
        }

        // Удаляем типы инфоблоков
        $types = ['calculator', 'calculator_catalog'];
        foreach ($types as $type) {
            \CIBlockType::Delete($type);
        }
    }

    /**
     * Удаление настроек модуля
     */
    public function deleteOptions(): void
    {
        Option::delete($this->MODULE_ID);
    }

    /**
     * Регистрация модуля
     */
    public function registerModule(): void
    {
        ModuleManager::registerModule($this->MODULE_ID);
    }

    /**
     * Проверка целостности установки
     * @return array Массив с результатами проверки
     */
    public function checkInstallationIntegrity(): array
    {
        $result = [
            'success' => true,
            'errors' => [],
            'warnings' => [],
        ];

        // Проверяем регистрацию модуля
        if (!ModuleManager::isModuleInstalled($this->MODULE_ID)) {
            $result['errors'][] = 'Модуль не зарегистрирован';
            $result['success'] = false;
        }

        // Проверяем наличие инфоблоков
        $iblockCodes = [
            'CALC_CONFIG',
            'CALC_SETTINGS',
            'CALC_MATERIALS',
            'CALC_MATERIALS_VARIANTS',
            'CALC_WORKS',
            'CALC_WORKS_VARIANTS',
            'CALC_EQUIPMENT',
            'CALC_DETAILS',
            'CALC_DETAILS_VARIANTS',
        ];

        foreach ($iblockCodes as $code) {
            $iblockId = (int)Option::get($this->MODULE_ID, 'IBLOCK_' . $code, 0);
            if ($iblockId <= 0) {
                $result['warnings'][] = "Инфоблок {$code} не найден в настройках";
            }
        }

        // Проверяем наличие файлов
        $docRoot = Application::getDocumentRoot();
        $jsDir = $docRoot . '/local/js/prospektweb.calc';
        $cssDir = $docRoot . '/local/css/prospektweb.calc';

        if (!is_dir($jsDir)) {
            $result['warnings'][] = 'Директория JS не найдена';
        }
        if (!is_dir($cssDir)) {
            $result['warnings'][] = 'Директория CSS не найдена';
        }

        return $result;
    }

    /**
     * Получение ID инфоблока по коду
     *
     * @param string $code Код инфоблока
     * @return int ID инфоблока
     */
    public static function getIblockId(string $code): int
    {
        return (int)Option::get('prospektweb.calc', 'IBLOCK_' . $code, 0);
    }
}
