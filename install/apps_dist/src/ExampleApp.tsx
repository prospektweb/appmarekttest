/**
 * Пример использования компонентов для интеграции с Битрикс
 * 
 * Этот файл демонстрирует, как использовать хук useBitrixBridge
 * и компонент OffersList в React-приложении
 */

import React from 'react';
import { useBitrixBridge } from './hooks/useBitrixBridge';
import { OffersList } from './components/OffersList';

/**
 * Пример компонента приложения с интеграцией Битрикс
 */
export const ExampleApp: React.FC = () => {
    // Используем хук для связи с Битрикс
    const {
        isReady,
        offers,
        apiBase,
        productId,
        iblockId,
        sessid,
        openOffer,
        removeOffer,
        closeDialog,
        sendToParent
    } = useBitrixBridge();

    // Пока приложение не готово, показываем загрузку
    if (!isReady) {
        return (
            <div className="flex items-center justify-center min-h-screen">
                <div className="text-center">
                    <p className="text-muted-foreground">Загрузка...</p>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-background p-4">
            <div className="max-w-4xl mx-auto space-y-4">
                {/* Заголовок */}
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">
                        Калькулятор себестоимости
                    </h1>
                    <button
                        onClick={closeDialog}
                        className="px-4 py-2 rounded bg-muted hover:bg-muted/80 transition-colors"
                    >
                        Закрыть
                    </button>
                </div>

                {/* Информация о продукте */}
                <div className="p-4 border rounded-lg bg-card">
                    <h2 className="text-sm font-semibold mb-2">
                        Информация о продукте
                    </h2>
                    <div className="text-sm text-muted-foreground space-y-1">
                        <p>ID продукта: {productId || 'Не указан'}</p>
                        <p>ID инфоблока: {iblockId || 'Не указан'}</p>
                        <p>API Base: {apiBase || 'Не указан'}</p>
                    </div>
                </div>

                {/* Список торговых предложений */}
                <OffersList
                    offers={offers}
                    onOpenOffer={openOffer}
                    onRemoveOffer={removeOffer}
                />

                {/* Пример кнопки для отправки данных родителю */}
                <div className="p-4 border rounded-lg bg-card">
                    <h2 className="text-sm font-semibold mb-2">
                        Действия
                    </h2>
                    <div className="flex gap-2">
                        <button
                            onClick={() => sendToParent('CALC_RESULT', {
                                totalCost: 1250.00,
                                offers: offers.map(o => o.id)
                            })}
                            className="px-4 py-2 rounded bg-primary text-primary-foreground hover:bg-primary/90 transition-colors"
                        >
                            Рассчитать
                        </button>
                        <button
                            onClick={() => sendToParent('CALC_SAVE_CONFIG', {
                                offers: offers.map(o => o.id),
                                timestamp: Date.now()
                            })}
                            className="px-4 py-2 rounded bg-secondary text-secondary-foreground hover:bg-secondary/90 transition-colors"
                        >
                            Сохранить конфигурацию
                        </button>
                    </div>
                </div>

                {/* Отладочная информация */}
                <details className="p-4 border rounded-lg bg-card">
                    <summary className="cursor-pointer text-sm font-semibold">
                        Отладочная информация
                    </summary>
                    <pre className="mt-2 text-xs overflow-auto">
                        {JSON.stringify({
                            isReady,
                            offers,
                            apiBase,
                            productId,
                            iblockId,
                            sessid: sessid ? '***' : ''
                        }, null, 2)}
                    </pre>
                </details>
            </div>
        </div>
    );
};

export default ExampleApp;
