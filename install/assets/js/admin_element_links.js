/**
 * Добавляет ссылки на связанные элементы в форме редактирования
 */
(function() {
    'use strict';

    function initElementLinks() {
        // Находим все span с названиями элементов (id начинается с sp_)
        const spans = document.querySelectorAll('span[id^="sp_"]');
        
        spans.forEach(function(span) {
            // Пропускаем пустые span
            if (!span.textContent.trim()) return;
            
            // Пропускаем уже обработанные span
            if (span.hasAttribute('data-processed')) return;
            span.setAttribute('data-processed', 'true');
            
            // Находим родительскую ячейку
            const td = span.closest('td');
            if (!td) return;
            
            // Находим input с ID элемента (первый input type="text" в td)
            const input = td.querySelector('input[type="text"][name^="PROP["]');
            if (!input) return;
            
            const elementId = input.value;
            if (!elementId || !/^\d+$/.test(elementId)) return;
            
            // Находим кнопку "..." и извлекаем IBLOCK_ID из onclick
            const button = td.querySelector('input[type="button"][value="..."]');
            if (!button) return;
            
            const onclick = button.getAttribute('onclick') || '';
            const iblockMatch = onclick.match(/IBLOCK_ID=(\d+)/);
            if (!iblockMatch) return;
            
            const iblockId = iblockMatch[1];
            
            // Определяем тип инфоблока (нужно получить из конфига или использовать дефолтный)
            const iblockType = getIblockType(iblockId);
            
            // Определяем язык интерфейса
            let lang = 'ru';
            if (typeof BX !== 'undefined' && BX.message) {
                lang = BX.message('LANGUAGE_ID') || 'ru';
            }
            
            // Создаём ссылку
            const link = document.createElement('a');
            link.href = '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=' + iblockId + 
                        '&type=' + iblockType + 
                        '&ID=' + elementId + 
                        '&lang=' + lang;
            link.textContent = span.textContent;
            link.style.cssText = 'color: #2067b0; text-decoration: none;';
            link.title = 'Открыть элемент ID: ' + elementId;
            
            // Hover эффект
            link.addEventListener('mouseenter', function() {
                this.style.textDecoration = 'underline';
            });
            link.addEventListener('mouseleave', function() {
                this.style.textDecoration = 'none';
            });
            
            // Заменяем содержимое span на ссылку
            span.textContent = '';
            span.appendChild(link);
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
            return mutation.addedNodes.length > 0;
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
    const form = document.querySelector('form[name="post_form"], form[name="form1"]');
    if (form) {
        observer.observe(form, { childList: true, subtree: true });
    }
})();
