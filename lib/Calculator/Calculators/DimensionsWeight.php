<?php

namespace Prospektweb\Calc\Calculator\Calculators;

use Prospektweb\Calc\Calculator\BaseCalculator;

/**
 * Калькулятор пересчёта габаритов и веса.
 */
class DimensionsWeight extends BaseCalculator
{
    /**
     * {@inheritdoc}
     */
    public function getCode(): string
    {
        return 'dimensions_weight';
    }

    /**
     * {@inheritdoc}
     */
    public function getTitle(): string
    {
        return 'Габариты и вес';
    }

    /**
     * {@inheritdoc}
     */
    public function getGroup(): string
    {
        return 'common';
    }

    /**
     * {@inheritdoc}
     */
    public function supportsChain(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getFieldsConfig(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getOptionsSpec(): array
    {
        $materialsIblockId = $this->configManager->getIblockId('CALC_MATERIALS_VARIANTS');

        return [
            [
                'code' => 'RECALC_WIDTH',
                'label' => 'Пересчитывать ширину (WIDTH)',
                'type' => 'checkbox',
                'default' => 'Y',
            ],
            [
                'code' => 'RECALC_LENGTH',
                'label' => 'Пересчитывать длину (LENGTH)',
                'type' => 'checkbox',
                'default' => 'Y',
            ],
            [
                'code' => 'RECALC_HEIGHT',
                'label' => 'Пересчитывать толщину (HEIGHT)',
                'type' => 'checkbox',
                'default' => 'Y',
            ],
            [
                'code' => 'RECALC_WEIGHT',
                'label' => 'Пересчитывать вес (WEIGHT)',
                'type' => 'checkbox',
                'default' => 'Y',
            ],
            [
                'code' => 'MEASURE_ID',
                'label' => 'Ед. измерения',
                'type' => 'select',
                'items' => $this->getMeasureItems(),
                'default' => null,
            ],
            [
                'code' => 'MATERIAL_COMPONENTS',
                'label' => 'Состоит из материалов',
                'type' => 'elements_with_quantity',
                'iblockId' => $materialsIblockId,
                'multiple' => true,
                'default' => [],
            ],
        ];
    }

    /**
     * Получает список единиц измерения.
     *
     * @return array
     */
    protected function getMeasureItems(): array
    {
        $items = [];

        if (!\Bitrix\Main\Loader::includeModule('catalog')) {
            return $items;
        }

        $measureRes = \CCatalogMeasure::getList();

        while ($measure = $measureRes->Fetch()) {
            $id = isset($measure['ID']) ? (int)$measure['ID'] : null;
            $title = $measure['MEASURE_TITLE'] ?? ($measure['TITLE'] ?? '');
            $symbol = $measure['SYMBOL'] ?? '';
            $label = trim($title . ($symbol ? ' (' . $symbol . ')' : ''));

            if ($id !== null) {
                $items[] = [
                    'value' => $id,
                    'label' => $label,
                ];
            }
        }

        return $items;
    }

    /**
     * {@inheritdoc}
     */
    public function calculate(array $ctx, array $options)
    {
        $offerId = $ctx['offerId'] ?? 0;
        $normalizedOptions = $this->normalizeOptionsWithDefaults($options);

        // Логика расчёта габаритов и веса
        // TODO: Реализовать полный расчёт на основе существующего CalcDimensionsWeight

        $this->log('calculate', [
            'offerId' => $offerId,
            'options' => $normalizedOptions,
        ]);

        return [
            'priceComponent' => [
                'label' => 'Габариты и вес',
                'cost' => 0,
                'meta' => [
                    'recalc_width' => $normalizedOptions['RECALC_WIDTH'] ?? 'Y',
                    'recalc_length' => $normalizedOptions['RECALC_LENGTH'] ?? 'Y',
                    'recalc_height' => $normalizedOptions['RECALC_HEIGHT'] ?? 'Y',
                    'recalc_weight' => $normalizedOptions['RECALC_WEIGHT'] ?? 'Y',
                ],
            ],
            'success' => true,
        ];
    }
}
