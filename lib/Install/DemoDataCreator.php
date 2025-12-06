<?php

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Loader;

/**
 * Класс для создания демо-данных.
 */
class DemoDataCreator
{
    /** @var array Созданные элементы */
    protected array $created = [];

    /** @var array Ошибки */
    protected array $errors = [];

    /**
     * Создаёт демо-данные.
     *
     * @param array $iblockIds Массив ID инфоблоков.
     *
     * @return array Результат.
     */
    public function create(array $iblockIds): array
    {
        $this->created = [];
        $this->errors = [];

        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            $this->errors[] = 'Не удалось загрузить модули';
            return $this->getResult();
        }

        // Создаём материалы
        $this->createMaterials($iblockIds);

        // Создаём работы
        $this->createWorks($iblockIds);

        // Создаём оборудование
        $this->createEquipment($iblockIds);

        // Создаём детали
        $this->createDetails($iblockIds);

        return $this->getResult();
    }

    /**
     * Создаёт материалы.
     *
     * @param array $iblockIds ID инфоблоков.
     */
    protected function createMaterials(array $iblockIds): void
    {
        $materialsIblockId = $iblockIds['CALC_MATERIALS'] ?? 0;
        $variantsIblockId = $iblockIds['CALC_MATERIALS_VARIANTS'] ?? 0;

        if ($materialsIblockId <= 0 || $variantsIblockId <= 0) {
            return;
        }

        // Мелованная бумага
        $paperId = $this->createElement($materialsIblockId, [
            'NAME' => 'Мелованная бумага',
            'CODE' => 'coated_paper',
            'PROPERTY_VALUES' => [
                'PARAMETRS' => [
                    ['VALUE' => '130', 'DESCRIPTION' => 'плотность, г/м²'],
                ],
            ],
        ]);

        if ($paperId) {
            $this->created[] = 'Материал: Мелованная бумага (ID: ' . $paperId . ')';

            // Варианты
            $variants = [
                ['NAME' => 'Мелованная бумага 130г', 'DENSITY' => 130],
                ['NAME' => 'Мелованная бумага 170г', 'DENSITY' => 170],
                ['NAME' => 'Мелованная бумага 250г', 'DENSITY' => 250],
                ['NAME' => 'Мелованная бумага 300г', 'DENSITY' => 300],
            ];

            foreach ($variants as $variant) {
                $variantId = $this->createElement($variantsIblockId, [
                    'NAME' => $variant['NAME'],
                    'PROPERTY_VALUES' => [
                        'CML2_LINK' => $paperId,
                        'DENSITY' => $variant['DENSITY'],
                        'WIDTH' => 320,
                        'LENGTH' => 450,
                    ],
                ]);

                if ($variantId) {
                    $this->created[] = 'Вариант: ' . $variant['NAME'] . ' (ID: ' . $variantId . ')';
                }
            }
        }

        // Плёнка для ламинации
        $filmId = $this->createElement($materialsIblockId, [
            'NAME' => 'Плёнка для ламинации',
            'CODE' => 'lamination_film',
        ]);

        if ($filmId) {
            $this->created[] = 'Материал: Плёнка для ламинации (ID: ' . $filmId . ')';

            $filmVariants = [
                ['NAME' => 'Плёнка глянец 30мкм', 'HEIGHT' => 30],
                ['NAME' => 'Плёнка мат 30мкм', 'HEIGHT' => 30],
            ];

            foreach ($filmVariants as $variant) {
                $variantId = $this->createElement($variantsIblockId, [
                    'NAME' => $variant['NAME'],
                    'PROPERTY_VALUES' => [
                        'CML2_LINK' => $filmId,
                        'HEIGHT' => $variant['HEIGHT'],
                    ],
                ]);

                if ($variantId) {
                    $this->created[] = 'Вариант: ' . $variant['NAME'] . ' (ID: ' . $variantId . ')';
                }
            }
        }
    }

    /**
     * Создаёт работы.
     *
     * @param array $iblockIds ID инфоблоков.
     */
    protected function createWorks(array $iblockIds): void
    {
        $worksIblockId = $iblockIds['CALC_WORKS'] ?? 0;
        $variantsIblockId = $iblockIds['CALC_WORKS_VARIANTS'] ?? 0;

        if ($worksIblockId <= 0 || $variantsIblockId <= 0) {
            return;
        }

        // Цифровая печать
        $printId = $this->createElement($worksIblockId, [
            'NAME' => 'Цифровая печать',
            'CODE' => 'digital_print',
        ]);

        if ($printId) {
            $this->created[] = 'Работа: Цифровая печать (ID: ' . $printId . ')';

            $variants = [
                ['NAME' => 'Цифровая печать 4+0'],
                ['NAME' => 'Цифровая печать 4+4'],
            ];

            foreach ($variants as $variant) {
                $variantId = $this->createElement($variantsIblockId, [
                    'NAME' => $variant['NAME'],
                    'PROPERTY_VALUES' => [
                        'CML2_LINK' => $printId,
                    ],
                ]);

                if ($variantId) {
                    $this->created[] = 'Вариант: ' . $variant['NAME'] . ' (ID: ' . $variantId . ')';
                }
            }
        }

        // Ламинирование
        $lamId = $this->createElement($worksIblockId, [
            'NAME' => 'Ламинирование',
            'CODE' => 'lamination',
        ]);

        if ($lamId) {
            $this->created[] = 'Работа: Ламинирование (ID: ' . $lamId . ')';

            $variants = [
                ['NAME' => 'Ламинирование одностороннее'],
                ['NAME' => 'Ламинирование двухстороннее'],
            ];

            foreach ($variants as $variant) {
                $variantId = $this->createElement($variantsIblockId, [
                    'NAME' => $variant['NAME'],
                    'PROPERTY_VALUES' => [
                        'CML2_LINK' => $lamId,
                    ],
                ]);

                if ($variantId) {
                    $this->created[] = 'Вариант: ' . $variant['NAME'] . ' (ID: ' . $variantId . ')';
                }
            }
        }
    }

    /**
     * Создаёт оборудование.
     *
     * @param array $iblockIds ID инфоблоков.
     */
    protected function createEquipment(array $iblockIds): void
    {
        $equipmentIblockId = $iblockIds['CALC_EQUIPMENT'] ?? 0;

        if ($equipmentIblockId <= 0) {
            return;
        }

        $equipment = [
            [
                'NAME' => 'Xerox Versant 180',
                'CODE' => 'xerox_versant_180',
                'PROPERTIES' => [
                    'MAX_WIDTH' => 330,
                    'MAX_LENGTH' => 488,
                    'START_COST' => 500,
                    'FIELDS' => '3,3,3,3',
                ],
            ],
            [
                'NAME' => 'GBC Titan 110',
                'CODE' => 'gbc_titan_110',
                'PROPERTIES' => [
                    'MAX_WIDTH' => 1100,
                    'MAX_LENGTH' => 0,
                    'START_COST' => 200,
                ],
            ],
        ];

        foreach ($equipment as $item) {
            $id = $this->createElement($equipmentIblockId, [
                'NAME' => $item['NAME'],
                'CODE' => $item['CODE'],
                'PROPERTY_VALUES' => $item['PROPERTIES'],
            ]);

            if ($id) {
                $this->created[] = 'Оборудование: ' . $item['NAME'] . ' (ID: ' . $id . ')';
            }
        }
    }

    /**
     * Создаёт детали.
     *
     * @param array $iblockIds ID инфоблоков.
     */
    protected function createDetails(array $iblockIds): void
    {
        $detailsIblockId = $iblockIds['CALC_DETAILS'] ?? 0;
        $variantsIblockId = $iblockIds['CALC_DETAILS_VARIANTS'] ?? 0;

        if ($detailsIblockId <= 0 || $variantsIblockId <= 0) {
            return;
        }

        $details = [
            [
                'NAME' => 'Листовка А4',
                'CODE' => 'leaflet_a4',
                'WIDTH' => 210,
                'LENGTH' => 297,
            ],
            [
                'NAME' => 'Листовка А5',
                'CODE' => 'leaflet_a5',
                'WIDTH' => 148,
                'LENGTH' => 210,
            ],
            [
                'NAME' => 'Визитка 90x50',
                'CODE' => 'business_card',
                'WIDTH' => 90,
                'LENGTH' => 50,
            ],
        ];

        foreach ($details as $detail) {
            $detailId = $this->createElement($detailsIblockId, [
                'NAME' => $detail['NAME'],
                'CODE' => $detail['CODE'],
            ]);

            if ($detailId) {
                $this->created[] = 'Деталь: ' . $detail['NAME'] . ' (ID: ' . $detailId . ')';

                // Вариант
                $variantId = $this->createElement($variantsIblockId, [
                    'NAME' => $detail['NAME'],
                    'PROPERTY_VALUES' => [
                        'CML2_LINK' => $detailId,
                        'WIDTH' => $detail['WIDTH'],
                        'LENGTH' => $detail['LENGTH'],
                    ],
                ]);

                if ($variantId) {
                    $this->created[] = 'Вариант детали: ' . $detail['NAME'] . ' (ID: ' . $variantId . ')';
                }
            }
        }
    }

    /**
     * Создаёт элемент инфоблока.
     *
     * @param int   $iblockId ID инфоблока.
     * @param array $data     Данные элемента.
     *
     * @return int ID элемента или 0.
     */
    protected function createElement(int $iblockId, array $data): int
    {
        // Проверяем, не существует ли элемент
        if (!empty($data['CODE'])) {
            $rsElement = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $iblockId, 'CODE' => $data['CODE']],
                false,
                ['nTopCount' => 1],
                ['ID']
            );

            if ($arElement = $rsElement->Fetch()) {
                return (int)$arElement['ID'];
            }
        }

        $fields = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => $data['NAME'],
            'CODE' => $data['CODE'] ?? '',
            'ACTIVE' => 'Y',
        ];

        if (isset($data['PROPERTY_VALUES'])) {
            $fields['PROPERTY_VALUES'] = $data['PROPERTY_VALUES'];
        }

        $el = new \CIBlockElement();
        $id = $el->Add($fields);

        if (!$id) {
            $this->errors[] = 'Не удалось создать элемент "' . $data['NAME'] . '": ' . $el->LAST_ERROR;
            return 0;
        }

        return (int)$id;
    }

    /**
     * Возвращает результат.
     *
     * @return array
     */
    protected function getResult(): array
    {
        return [
            'created' => $this->created,
            'errors' => $this->errors,
        ];
    }
}
