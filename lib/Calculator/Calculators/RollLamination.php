<?php

namespace Prospektweb\Calc\Calculator\Calculators;

use Prospektweb\Calc\Calculator\BaseCalculator;

/**
 * Калькулятор рулонного ламинирования.
 */
class RollLamination extends BaseCalculator
{
    /**
     * {@inheritdoc}
     */
    public function getCode(): string
    {
        return 'roll_lamination';
    }

    /**
     * {@inheritdoc}
     */
    public function getTitle(): string
    {
        return 'Ламинирование рулонное';
    }

    /**
     * {@inheritdoc}
     */
    public function getGroup(): string
    {
        return 'postpress';
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
    public function supportsFinalization(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function canChangePrice(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function canBeFirst(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresBefore(): array
    {
        return ['digital_sheet'];
    }

    /**
     * {@inheritdoc}
     */
    public function getFieldsConfig(): array
    {
        return [
            'operation' => [
                'visible' => true,
                'required' => true,
            ],
            'equipment' => [
                'visible' => true,
                'required' => true,
            ],
            'material' => [
                'visible' => true,
                'required' => false,
                'quantityField' => false, // Количество рассчитывается автоматически
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getExtraOptions(): array
    {
        return [
            [
                'code' => 'DOUBLE_SIDED',
                'label' => 'Двухстороннее ламинирование',
                'type' => 'checkbox',
                'default' => 'Y',
            ],
            [
                'code' => 'WASTE_PERCENT',
                'label' => 'Процент отходов',
                'type' => 'number',
                'default' => 5,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getOptionsSpec(): array
    {
        $materialsIblockId = $this->configManager->getIblockId('CALC_MATERIALS_VARIANTS');
        $worksIblockId = $this->configManager->getIblockId('CALC_WORKS_VARIANTS');

        return [
            [
                'code' => 'FILM_ID',
                'label' => 'Плёнка',
                'type' => 'element',
                'iblockId' => $materialsIblockId,
                'multiple' => false,
            ],
            [
                'code' => 'DOUBLE_SIDED',
                'label' => 'Двухстороннее ламинирование',
                'type' => 'checkbox',
                'default' => 'Y',
            ],
            [
                'code' => 'WORK_ID',
                'label' => 'Работа',
                'type' => 'element',
                'iblockId' => $worksIblockId,
                'multiple' => false,
            ],
            [
                'code' => 'WASTE_PERCENT',
                'label' => 'Процент отходов',
                'type' => 'number',
                'default' => 5,
                'step' => '1',
                'min' => 0,
                'max' => 100,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function calculate(array $ctx, array $options)
    {
        $offerId = $ctx['offerId'] ?? 0;
        $isLastStep = $ctx['isLastStep'] ?? true;
        $normalizedOptions = $this->normalizeOptionsWithDefaults($options);

        // Получаем параметры
        $filmId = (int)($normalizedOptions['FILM_ID'] ?? 0);
        $workId = (int)($normalizedOptions['WORK_ID'] ?? 0);
        $doubleSided = ($normalizedOptions['DOUBLE_SIDED'] ?? 'Y') === 'Y';
        $wastePercent = (float)($normalizedOptions['WASTE_PERCENT'] ?? 5);

        // Логика расчёта рулонного ламинирования
        // TODO: Реализовать полный расчёт

        $calculatedCost = 0;

        $priceComponent = [
            'label' => 'Ламинирование рулонное',
            'cost' => $calculatedCost,
            'meta' => [
                'film_id' => $filmId,
                'work_id' => $workId,
                'double_sided' => $doubleSided,
                'waste_percent' => $wastePercent,
            ],
        ];

        $this->log('calculate', [
            'offerId' => $offerId,
            'options' => $normalizedOptions,
            'priceComponent' => $priceComponent,
        ]);

        if ($isLastStep) {
            return [
                'priceComponent' => $priceComponent,
                'success' => true,
                'finalized' => true,
            ];
        }

        return [
            'priceComponent' => $priceComponent,
            'success' => true,
        ];
    }
}
