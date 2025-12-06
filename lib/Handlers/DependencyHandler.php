<?php

namespace Prospektweb\Calc\Handlers;

use Prospektweb\Calc\Services\DependencyTracker;

/**
 * Обработчик для отслеживания зависимостей при изменении элементов.
 */
class DependencyHandler
{
    /**
     * Обработчик события OnAfterIBlockElementUpdate.
     *
     * @param array $arFields Поля элемента.
     */
    public static function onElementUpdate(array &$arFields): void
    {
        $elementId = (int)($arFields['ID'] ?? 0);
        $iblockId = (int)($arFields['IBLOCK_ID'] ?? 0);

        if ($elementId <= 0 || $iblockId <= 0) {
            return;
        }

        try {
            $tracker = new DependencyTracker();
            $tracker->handleElementChange($elementId, $iblockId);
        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/dependency_handler_error.log';
            $logDir = dirname($logFile);

            if (!is_dir($logDir)) {
                mkdir($logDir, 0750, true);
            }

            file_put_contents(
                $logFile,
                date('c') . ' Error: ' . $e->getMessage() . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
    }
}
