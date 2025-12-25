<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

$iblockId = $arResult['IBLOCK_ID'];
$elementId = $arResult['ELEMENT_ID'];
$element = $arResult['ELEMENT'] ?? [];
$properties = $arResult['PROPERTIES'] ?? [];
$propertyEnums = $arResult['PROPERTY_ENUMS'] ?? [];

// Текущие значения полей
$name = htmlspecialcharsbx($element['NAME'] ?? '');
$active = ($element['ACTIVE'] ?? 'Y') === 'Y';
$fieldCode = htmlspecialcharsbx($properties['FIELD_CODE']['VALUE'] ?? '');
$fieldType = $properties['FIELD_TYPE']['VALUE_ENUM_ID'] ?? '';
$defaultValue = $properties['DEFAULT_VALUE']['VALUE'] ?? ''; // Не экранируем здесь, т.к. используем для сравнения
$isRequired = $properties['IS_REQUIRED']['VALUE_ENUM_ID'] ?? '';
$unit = htmlspecialcharsbx($properties['UNIT']['VALUE'] ?? '');
$minValue = htmlspecialcharsbx($properties['MIN_VALUE']['VALUE'] ?? '');
$maxValue = htmlspecialcharsbx($properties['MAX_VALUE']['VALUE'] ?? '');
$stepValue = htmlspecialcharsbx($properties['STEP_VALUE']['VALUE'] ?? '');
$maxLength = htmlspecialcharsbx($properties['MAX_LENGTH']['VALUE'] ?? '');
$sortOrder = htmlspecialcharsbx($properties['SORT_ORDER']['VALUE'] ?? '500');

// Загружаем OPTIONS как множественное свойство с описанием
$optionsData = [];
if ($elementId > 0) {
    $rsOptions = CIBlockElement::GetProperty(
        $iblockId,
        $elementId,
        ['sort' => 'asc'],
        ['CODE' => 'OPTIONS']
    );
    
    while ($option = $rsOptions->Fetch()) {
        if (!empty($option['VALUE']) || !empty($option['DESCRIPTION'])) {
            $optionsData[] = [
                'VALUE' => $option['VALUE'],
                'DESCRIPTION' => $option['DESCRIPTION'],
            ];
        }
    }
}

// Получаем XML_ID для типа поля
$currentFieldTypeXmlId = '';
if ($fieldType) {
    foreach ($propertyEnums['FIELD_TYPE'] ?? [] as $enum) {
        if ($enum['ID'] == $fieldType) {
            $currentFieldTypeXmlId = $enum['XML_ID'];
            break;
        }
    }
}
?>

<?php if (!empty($arResult['ERRORS'])): ?>
    <div class="adm-info-message-wrap adm-info-message-red">
        <div class="adm-info-message">
            <?php foreach ($arResult['ERRORS'] as $error): ?>
                <div><?= htmlspecialcharsbx($error) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($arResult['SUCCESS']): ?>
    <div class="adm-info-message-wrap adm-info-message-green">
        <div class="adm-info-message">
            Изменения сохранены
        </div>
    </div>
<?php endif; ?>

<form method="POST" id="custom-field-form" class="custom-field-edit-form">
    <?= bitrix_sessid_post() ?>
    
    <div class="adm-detail-content">
        <div class="adm-detail-content-item-block">
            <!-- Базовые поля -->
            <table class="adm-detail-content-table edit-table">
                <tbody>
                    <tr>
                        <td class="adm-detail-content-cell-l" width="40%">
                            <span class="required">Название поля:</span>
                        </td>
                        <td class="adm-detail-content-cell-r">
                            <input type="text" name="NAME" value="<?= $name ?>" size="50" required>
                        </td>
                    </tr>
                    
                    <tr>
                        <td class="adm-detail-content-cell-l">
                            Активность:
                        </td>
                        <td class="adm-detail-content-cell-r">
                            <input type="checkbox" name="ACTIVE" value="Y" <?= $active ? 'checked' : '' ?>>
                        </td>
                    </tr>
                    
                    <tr>
                        <td class="adm-detail-content-cell-l">
                            <span class="required">Символьный код:</span>
                        </td>
                        <td class="adm-detail-content-cell-r">
                            <input type="text" 
                                   name="PROPERTY_VALUES[FIELD_CODE]" 
                                   value="<?= $fieldCode ?>" 
                                   size="50" 
                                   required 
                                   pattern="[A-Z0-9_]+"
                                   title="Только заглавные латинские буквы, цифры и подчёркивание">
                            <div class="adm-info-message">
                                Например: BLEED, PAPER_TYPE
                            </div>
                        </td>
                    </tr>
                    
                    <tr>
                        <td class="adm-detail-content-cell-l">
                            <span class="required">Тип поля:</span>
                        </td>
                        <td class="adm-detail-content-cell-r">
                            <select name="PROPERTY_VALUES[FIELD_TYPE]" id="field-type-select" required>
                                <option value="">-- Выберите тип --</option>
                                <?php foreach ($propertyEnums['FIELD_TYPE'] ?? [] as $enum): ?>
                                    <option value="<?= $enum['ID'] ?>" 
                                            data-xml-id="<?= htmlspecialcharsbx($enum['XML_ID']) ?>"
                                            <?= $enum['ID'] == $fieldType ? 'selected' : '' ?>>
                                        <?= htmlspecialcharsbx($enum['VALUE']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <td class="adm-detail-content-cell-l">
                            Обязательное:
                        </td>
                        <td class="adm-detail-content-cell-r">
                            <select name="PROPERTY_VALUES[IS_REQUIRED]">
                                <?php foreach ($propertyEnums['IS_REQUIRED'] ?? [] as $enum): ?>
                                    <option value="<?= $enum['ID'] ?>" <?= $enum['ID'] == $isRequired ? 'selected' : '' ?>>
                                        <?= htmlspecialcharsbx($enum['VALUE']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <td class="adm-detail-content-cell-l">
                            Значение по умолчанию:
                        </td>
                        <td class="adm-detail-content-cell-r">
                            <input type="text" name="PROPERTY_VALUES[DEFAULT_VALUE]" value="<?= htmlspecialcharsbx($defaultValue) ?>" size="50">
                        </td>
                    </tr>
                    
                    <tr>
                        <td class="adm-detail-content-cell-l">
                            Порядок сортировки:
                        </td>
                        <td class="adm-detail-content-cell-r">
                            <input type="number" name="PROPERTY_VALUES[SORT_ORDER]" value="<?= $sortOrder ?>" size="10">
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Поля для типа "number" -->
            <div id="number-fields" class="type-specific-fields" style="display: none;">
                <h3>Параметры числового поля</h3>
                <table class="adm-detail-content-table edit-table">
                    <tbody>
                        <tr>
                            <td class="adm-detail-content-cell-l" width="40%">
                                Единица измерения:
                            </td>
                            <td class="adm-detail-content-cell-r">
                                <input type="text" name="PROPERTY_VALUES[UNIT]" value="<?= $unit ?>" size="20" placeholder="мм, шт, %">
                            </td>
                        </tr>
                        <tr>
                            <td class="adm-detail-content-cell-l">
                                Минимальное значение:
                            </td>
                            <td class="adm-detail-content-cell-r">
                                <input type="number" step="any" name="PROPERTY_VALUES[MIN_VALUE]" value="<?= $minValue ?>" size="20">
                            </td>
                        </tr>
                        <tr>
                            <td class="adm-detail-content-cell-l">
                                Максимальное значение:
                            </td>
                            <td class="adm-detail-content-cell-r">
                                <input type="number" step="any" name="PROPERTY_VALUES[MAX_VALUE]" value="<?= $maxValue ?>" size="20">
                            </td>
                        </tr>
                        <tr>
                            <td class="adm-detail-content-cell-l">
                                Шаг:
                            </td>
                            <td class="adm-detail-content-cell-r">
                                <input type="number" step="any" name="PROPERTY_VALUES[STEP_VALUE]" value="<?= $stepValue ?>" size="20">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Поля для типа "text" -->
            <div id="text-fields" class="type-specific-fields" style="display: none;">
                <h3>Параметры текстового поля</h3>
                <table class="adm-detail-content-table edit-table">
                    <tbody>
                        <tr>
                            <td class="adm-detail-content-cell-l" width="40%">
                                Максимальная длина:
                            </td>
                            <td class="adm-detail-content-cell-r">
                                <input type="number" name="PROPERTY_VALUES[MAX_LENGTH]" value="<?= $maxLength ?>" size="20">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Поля для типа "select" -->
            <div id="select-fields" class="type-specific-fields" style="display: none;">
                <h3>Варианты для выпадающего списка</h3>
                
                <div class="calc-options-editor">
                    <div class="calc-options-list" id="options-list">
                        <?php if (!empty($optionsData)): ?>
                            <?php foreach ($optionsData as $index => $option): ?>
                                <div class="calc-option-row" data-index="<?= $index ?>">
                                    <input type="radio" 
                                           name="DEFAULT_OPTION" 
                                           value="<?= $index ?>"
                                           <?= ($defaultValue === $option['VALUE']) ? 'checked' : '' ?>
                                           title="Сделать значением по умолчанию">
                                    <input type="text" 
                                           name="PROPERTY_VALUES[OPTIONS][<?= $index ?>][VALUE]" 
                                           class="calc-option-value"
                                           placeholder="Код (например: glossy)"
                                           value="<?= htmlspecialcharsbx($option['VALUE']) ?>">
                                    <input type="text" 
                                           name="PROPERTY_VALUES[OPTIONS][<?= $index ?>][DESCRIPTION]" 
                                           class="calc-option-label"
                                           placeholder="Название (например: Глянцевая)"
                                           value="<?= htmlspecialcharsbx($option['DESCRIPTION']) ?>">
                                    <button type="button" class="calc-option-remove" title="Удалить">✕</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <button type="button" class="calc-option-add" id="add-option-btn">
                        <span>+ Добавить вариант</span>
                    </button>
                    
                    <div class="adm-info-message" style="margin-top: 15px;">
                        <strong>Как использовать:</strong><br>
                        • <strong>Код</strong> — техническое значение (например: <code>glossy</code>)<br>
                        • <strong>Название</strong> — текст для отображения пользователю (например: <code>Глянцевая</code>)<br>
                        • <strong>○</strong> — выберите вариант по умолчанию
                    </div>
                </div>
            </div>
            
            <!-- Превью поля -->
            <div id="field-preview" style="display: none;">
                <h3>Предварительный просмотр</h3>
                <div id="preview-container" class="field-preview-container"></div>
            </div>
        </div>
    </div>
    
    <div class="adm-detail-content-btns">
        <button type="submit" name="save" class="adm-btn-save">Сохранить</button>
        <a href="<?= htmlspecialcharsbx($arResult['LIST_URL']) ?>" class="adm-btn">Отмена</a>
    </div>
</form>

<script>
    // Класс для управления опциями
    class OptionsEditor {
        constructor(container) {
            this.container = container;
            this.list = document.getElementById('options-list');
            this.addBtn = document.getElementById('add-option-btn');
            this.index = this.list ? this.list.children.length : 0;
            
            this.bindEvents();
        }
        
        bindEvents() {
            if (!this.addBtn || !this.list) return;
            
            // Добавление новой опции
            this.addBtn.addEventListener('click', () => this.addOption());
            
            // Удаление опции
            this.list.addEventListener('click', (e) => {
                if (e.target.closest('.calc-option-remove')) {
                    e.target.closest('.calc-option-row').remove();
                }
            });
        }
        
        addOption() {
            const row = document.createElement('div');
            const safeIndex = parseInt(this.index, 10); // Ensure it's a safe integer
            row.className = 'calc-option-row';
            row.dataset.index = safeIndex;
            row.innerHTML = `
                <input type="radio" 
                       name="DEFAULT_OPTION" 
                       value="${safeIndex}"
                       title="Сделать значением по умолчанию">
                <input type="text" 
                       name="PROPERTY_VALUES[OPTIONS][${safeIndex}][VALUE]" 
                       class="calc-option-value"
                       placeholder="Код (например: glossy)">
                <input type="text" 
                       name="PROPERTY_VALUES[OPTIONS][${safeIndex}][DESCRIPTION]" 
                       class="calc-option-label"
                       placeholder="Название (например: Глянцевая)">
                <button type="button" class="calc-option-remove" title="Удалить">✕</button>
            `;
            
            this.list.appendChild(row);
            this.index++;
            
            // Фокус на новое поле
            row.querySelector('.calc-option-value').focus();
        }
    }
    
    // Инициализация при загрузке
    BX.ready(function() {
        var fieldTypeSelect = document.getElementById('field-type-select');
        var currentType = '<?= $currentFieldTypeXmlId ?>';
        
        // Инициализируем редактор опций
        var optionsEditor = new OptionsEditor(document.querySelector('.calc-options-editor'));
        
        // Показываем нужные поля при загрузке
        if (currentType) {
            toggleFieldsByType(currentType);
        }
        
        // Обработчик изменения типа
        if (fieldTypeSelect) {
            fieldTypeSelect.addEventListener('change', function() {
                var selectedOption = this.options[this.selectedIndex];
                var xmlId = selectedOption.getAttribute('data-xml-id');
                toggleFieldsByType(xmlId);
            });
        }
    });
    
    function toggleFieldsByType(type) {
        // Скрываем все специфичные поля
        var allTypeFields = document.querySelectorAll('.type-specific-fields');
        allTypeFields.forEach(function(el) {
            el.style.display = 'none';
        });
        
        // Показываем нужные поля
        switch(type) {
            case 'number':
                document.getElementById('number-fields').style.display = 'block';
                break;
            case 'text':
                document.getElementById('text-fields').style.display = 'block';
                break;
            case 'select':
                document.getElementById('select-fields').style.display = 'block';
                break;
            case 'checkbox':
                // Для checkbox нет дополнительных полей
                break;
        }
    }
</script>
