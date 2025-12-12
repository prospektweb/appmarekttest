<?php

namespace Prospektweb\Calc\Calculator\Calculators;

use Prospektweb\Calc\Calculator\BaseCalculator;

/**
 * Калькулятор цифровой листовой печати.
 */
class DigitalSheet extends BaseCalculator
{
    /**
     * {@inheritdoc}
     */
    public function getCode(): string
    {
        return 'digital_sheet';
    }

    /**
     * {@inheritdoc}
     */
    public function getTitle(): string
    {
        return 'Цифровая листовая печать';
    }

    /**
     * {@inheritdoc}
     */
    public function getGroup(): string
    {
        return 'digital_print';
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
    public function getFieldsConfig(): array
    {
        return [
            'operation' => [
                'visible' => true,
                'required' => true,
                'quantityField' => true,
            ],
            'equipment' => [
                'visible' => true,
                'required' => true,
            ],
            'material' => [
                'visible' => true,
                'required' => true,
                'quantityField' => true,
                'quantityUnit' => 'л',
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
                'code' => 'FIELD_MM',
                'label' => 'Припуски, мм',
                'type' => 'number',
                'default' => 2,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getOptionsSpec(): array
    {
        $materialsIblockId = $this->configManager->getIblockId('CALC_MATERIALS_VARIANTS');
        $operationsIblockId = $this->configManager->getIblockId('CALC_OPERATIONS_VARIANTS');

        return [
            [
                'code' => 'PAPER_ID',
                'label' => 'Бумага',
                'type' => 'element',
                'iblockId' => $materialsIblockId,
                'multiple' => false,
            ],
            [
                'code' => 'PRESS_ID',
                'label' => 'Печать',
                'type' => 'element',
                'iblockId' => $operationsIblockId,
                'multiple' => false,
            ],
            [
                'code' => 'FIELD_ONE_SIDE_VALUE_MM',
                'label' => 'Припуски, мм',
                'type' => 'number',
                'default' => 2,
                'step' => '1',
                'min' => 0,
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
        $paperId = (int)($normalizedOptions['PAPER_ID'] ?? 0);
        $pressId = (int)($normalizedOptions['PRESS_ID'] ?? 0);
        $bleed = (float)($normalizedOptions['FIELD_ONE_SIDE_VALUE_MM'] ?? 2);

        // Логика расчёта цифровой печати
        // TODO: Реализовать полный расчёт на основе существующего CalcDigitalSheet

        $calculatedCost = 0;

        $priceComponent = [
            'label' => 'Цифровая листовая печать',
            'cost' => $calculatedCost,
            'meta' => [
                'paper_id' => $paperId,
                'press_id' => $pressId,
                'bleed' => $bleed,
            ],
        ];

        $this->log('calculate', [
            'offerId' => $offerId,
            'options' => $normalizedOptions,
            'priceComponent' => $priceComponent,
        ]);

        if ($isLastStep) {
            // Финализация цен
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
