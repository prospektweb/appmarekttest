<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;

/**
 * Сервис подготовки INIT payload для React-калькулятора
 */
class InitPayloadService
{
    /** @var string ID модуля */
    private const MODULE_ID = 'prospektweb.calc';

    /**
     * Подготовить INIT payload для отправки в iframe
     *
     * @param array $offerIds ID торговых предложений
     * @param string $siteId ID сайта
     * @return array
     * @throws \Exception
     */
    public function prepareInitPayload(array $offerIds, string $siteId): array
    {
        if (empty($offerIds)) {
            throw new \Exception('Список торговых предложений не может быть пустым');
        }

        Loader::includeModule('iblock');
        Loader::includeModule('catalog');

        // Собираем информацию о торговых предложениях
        $selectedOffers = $this->loadOffers($offerIds);

        // Определяем режим работы
        $mode = $this->determineMode($selectedOffers);

        // Собираем контекст
        $context = $this->buildContext($siteId);

        // Собираем ID инфоблоков
        $iblocks = $this->getIblocks();

        $payload = [
            'mode' => $mode,
            'context' => $context,
            'iblocks' => $iblocks,
            'selectedOffers' => $selectedOffers,
        ];

        // Если режим EXISTING_CONFIG - загружаем конфигурацию
        if ($mode === 'EXISTING_CONFIG' && !empty($selectedOffers[0]['configId'])) {
            $config = $this->loadConfiguration($selectedOffers[0]['configId']);
            if ($config) {
                $payload['config'] = $config;
            }
        }

        return $payload;
    }

    /**
     * Загрузить информацию о торговых предложениях
     *
     * @param array $offerIds
     * @return array
     */
    private function loadOffers(array $offerIds): array
    {
        $offers = [];
        $propertyConfigId = Option::get(self::MODULE_ID, 'PROPERTY_CONFIG_ID', 'CONFIG_ID');

        foreach ($offerIds as $offerId) {
            $offerId = (int)$offerId;
            if ($offerId <= 0) {
                continue;
            }

            $element = \CIBlockElement::GetByID($offerId)->Fetch();
            if (!$element) {
                continue;
            }

            // Получаем свойства элемента
            $properties = [];
            $dbProperties = \CIBlockElement::GetProperty(
                $element['IBLOCK_ID'],
                $offerId,
                ['sort' => 'asc'],
                ['CODE' => $propertyConfigId]
            );
            while ($prop = $dbProperties->Fetch()) {
                $properties[$prop['CODE']] = $prop['VALUE'];
            }

            $offer = [
                'id' => $offerId,
                'productId' => (int)($element['PROPERTY_CML2_LINK_VALUE'] ?? 0),
                'name' => $element['NAME'] ?? '',
                'fields' => [],
            ];

            // Добавляем configId если есть
            if (!empty($properties[$propertyConfigId])) {
                $offer['configId'] = (int)$properties[$propertyConfigId];
            }

            $offers[] = $offer;
        }

        return $offers;
    }

    /**
     * Определить режим работы калькулятора
     *
     * @param array $offers
     * @return string 'NEW_CONFIG' | 'EXISTING_CONFIG'
     */
    private function determineMode(array $offers): string
    {
        if (empty($offers)) {
            return 'NEW_CONFIG';
        }

        // Проверяем наличие configId у первого элемента
        $firstConfigId = $offers[0]['configId'] ?? null;
        
        if ($firstConfigId === null) {
            return 'NEW_CONFIG';
        }

        // Проверяем, что у всех одинаковый configId
        foreach ($offers as $offer) {
            $currentConfigId = $offer['configId'] ?? null;
            if ($currentConfigId !== $firstConfigId) {
                return 'NEW_CONFIG';
            }
        }

        return 'EXISTING_CONFIG';
    }

    /**
     * Собрать контекст запроса
     *
     * @param string $siteId
     * @return array
     */
    private function buildContext(string $siteId): array
    {
        global $USER;

        return [
            'siteId' => $siteId,
            'userId' => (string)($USER->GetID() ?? '0'),
            'lang' => LANGUAGE_ID ?? 'ru',
            'timestamp' => time(),
        ];
    }

    /**
     * Получить ID инфоблоков из настроек
     *
     * @return array
     */
    private function getIblocks(): array
    {
        return [
            'materials' => (int)Option::get(self::MODULE_ID, 'IBLOCK_MATERIALS', 0),
            'operations' => (int)Option::get(self::MODULE_ID, 'IBLOCK_OPERATIONS', 0),
            'equipment' => (int)Option::get(self::MODULE_ID, 'IBLOCK_EQUIPMENT', 0),
            'details' => (int)Option::get(self::MODULE_ID, 'IBLOCK_DETAILS', 0),
            'calculators' => (int)Option::get(self::MODULE_ID, 'IBLOCK_CALCULATORS', 0),
            'configurations' => (int)Option::get(self::MODULE_ID, 'IBLOCK_CONFIGURATIONS', 0),
        ];
    }

    /**
     * Загрузить конфигурацию по ID
     *
     * @param int $configId
     * @return array|null
     */
    private function loadConfiguration(int $configId): ?array
    {
        if ($configId <= 0) {
            return null;
        }

        $iblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_CONFIGURATIONS', 0);
        if ($iblockId <= 0) {
            return null;
        }

        $element = \CIBlockElement::GetByID($configId)->Fetch();
        if (!$element || (int)$element['IBLOCK_ID'] !== $iblockId) {
            return null;
        }

        // Получаем детальное описание (JSON конфигурации)
        $detailText = $element['DETAIL_TEXT'] ?? '';
        $data = [];
        if (!empty($detailText)) {
            $decoded = json_decode($detailText, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return [
            'id' => $configId,
            'name' => $element['NAME'] ?? '',
            'data' => $data,
        ];
    }
}
