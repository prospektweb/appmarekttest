/**
 * Добавляет ссылки на связанные элементы в форме редактирования
 */
(function() {
    'use strict';

    /**
     * Построить ссылку на элемент в указанном span
     *
     * @param {HTMLSpanElement} span
     * @param {HTMLInputElement} input
     * @param {HTMLInputElement} button
     */
    function updateSpanLink(span, input, button) {
        const existingLink = span.querySelector('a');
        const onclick = button.getAttribute('onclick') || '';
        const iblockMatch = onclick.match(/IBLOCK_ID=(\d+)/);
        if (!iblockMatch) {
            return;
        }

        const iblockId = iblockMatch[1];
        const elementId = (input.value || '').trim();
        const hasValidElementId = elementId && /^\d+$/.test(elementId);

        // Текст ссылки берём из уже созданной ссылки (если есть) или текущего текста span
        const linkText = existingLink
            ? existingLink.textContent.trim()
            : span.textContent.trim();

        if (!hasValidElementId || !linkText) {
            // Если ID пустой, убираем созданную ссылку и выходим
            if (existingLink) {
                span.textContent = linkText;
            }
            return;
        }

        // Проверяем, что мы не создаём ссылку повторно на тот же элемент
        if (
            existingLink &&
            existingLink.dataset.elementId === elementId &&
            existingLink.dataset.iblockId === iblockId
        ) {
            return;
        }

        const iblockType = getIblockType(iblockId);

        // Определяем язык интерфейса
        let lang = 'ru';
        if (typeof BX !== 'undefined' && BX.message) {
            lang = BX.message('LANGUAGE_ID') || 'ru';
        }

        const linkUrl = getElementLinkUrl({
            iblockId,
            iblockType,
            elementId,
            lang,
        });

        if (!linkUrl) {
            return;
        }

        const link = document.createElement('a');
        link.href = linkUrl;
        link.textContent = linkText;
        link.style.cssText = 'color: #2067b0; text-decoration: none;';
        link.title = 'Открыть элемент ID: ' + elementId;
        link.dataset.elementId = elementId;
        link.dataset.iblockId = iblockId;

        link.addEventListener('mouseenter', function() {
            this.style.textDecoration = 'underline';
        });
        link.addEventListener('mouseleave', function() {
            this.style.textDecoration = 'none';
        });

        span.textContent = '';
        span.appendChild(link);
    }

    function initElementLinks() {
        // Находим все span с названиями элементов (id начинается с sp_)
        const spans = document.querySelectorAll('span[id^="sp_"]');
        
        spans.forEach(function(span) {
            // Находим родительскую ячейку
            const td = span.closest('td');
            if (!td) return;
            
            // Находим input с ID элемента (первый input type="text" в td)
            const input = td.querySelector('input[type="text"][name^="PROP["]');
            if (!input) return;
            
            // Находим кнопку "..." и извлекаем IBLOCK_ID из onclick
            const button = td.querySelector('input[type="button"][value="..."]');
            if (!button) return;
            
            // Навешиваем обработчики изменения значения
            if (!input.dataset.pwCalcLinkBound) {
                ['change', 'input', 'keyup'].forEach(function(eventName) {
                    input.addEventListener(eventName, function() {
                        updateSpanLink(span, input, button);
                    });
                });
                input.dataset.pwCalcLinkBound = 'true';
            }

            updateSpanLink(span, input, button);
        });
    }
    
    /**
     * Получить тип инфоблока по ID
     * Использует глобальный объект PROSPEKTWEB_CALC_IBLOCK_TYPES если доступен
     */
    function getIblockType(iblockId) {
        // Проверяем глобальный конфиг модуля
        if (window.PROSPEKTWEB_CALC_IBLOCK_TYPES && window.PROSPEKTWEB_CALC_IBLOCK_TYPES[iblockId]) {
            return window.PROSPEKTWEB_CALC_IBLOCK_TYPES[iblockId];
        }
        
        // Пытаемся определить по ID (на основе типичных диапазонов)
        // Это fallback, основной источник — PROSPEKTWEB_CALC_IBLOCK_TYPES
        return 'calculator';
    }

    function getProductId() {
        const productInput = document.querySelector('input[name="PRODUCT_ID"]');
        if (productInput && productInput.value) {
            const value = productInput.value.trim();
            if (/^\d+$/.test(value)) {
                return value;
            }
        }

        const searchParams = new URLSearchParams(window.location.search);
        const productIdFromUrl = searchParams.get('PRODUCT_ID');
        if (productIdFromUrl && /^\d+$/.test(productIdFromUrl)) {
            return productIdFromUrl;
        }

        return null;
    }

    function getElementLinkUrl(params) {
        const { iblockId, iblockType, elementId, lang } = params;
        const isSkuEditor = window.location.pathname.indexOf('iblock_subelement_edit.php') !== -1;
        const productId = isSkuEditor ? getProductId() : null;

        if (isSkuEditor && productId) {
            return (
                '/bitrix/admin/iblock_subelement_edit.php?IBLOCK_ID=' +
                iblockId +
                '&type=' +
                iblockType +
                '&PRODUCT_ID=' +
                productId +
                '&ID=' +
                elementId +
                '&lang=' +
                lang
            );
        }

        return (
            '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=' +
            iblockId +
            '&type=' +
            iblockType +
            '&ID=' +
            elementId +
            '&lang=' +
            lang
        );
    }
    
    // Запускаем после загрузки DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initElementLinks);
    } else {
        initElementLinks();
    }
    
    // Также отслеживаем динамическое добавление элементов (для множественных свойств)
    // Используем MutationObserver с debouncing
    let mutationTimeout = null;
    const observer = new MutationObserver(function(mutations) {
        // Проверяем, есть ли добавленные узлы
        const hasAddedNodes = mutations.some(function(mutation) {
            return mutation.addedNodes.length > 0 || mutation.type === 'characterData';
        });
        
        if (hasAddedNodes) {
            // Отменяем предыдущий таймер если есть
            if (mutationTimeout) {
                clearTimeout(mutationTimeout);
            }
            
            // Устанавливаем новый таймер с debouncing
            mutationTimeout = setTimeout(function() {
                initElementLinks();
                mutationTimeout = null;
            }, 150);
        }
    });
    
    // Наблюдаем за изменениями в форме
    const form =
        document.querySelector(
            'form[name="post_form"], form[name="form1"], form[name="form_element"], form[name="form_e_list"]'
        ) || document.querySelector('form') || document.body;
    if (form) {
        observer.observe(form, { childList: true, subtree: true, characterData: true });
    }
})();
