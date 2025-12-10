import { useState, useEffect, useCallback } from 'react';

/**
 * Интерфейс торгового предложения
 */
export interface Offer {
    id: number;
    name: string;
    editUrl: string;
    productId: number;
    iblockId: number;
}

/**
 * Интерфейс состояния моста Битрикс
 */
export interface BitrixBridgeState {
    isReady: boolean;
    offers: Offer[];
    apiBase: string;
    productId: number | null;
    iblockId: number | null;
    sessid: string;
}

/**
 * Интерфейс методов моста Битрикс
 */
export interface BitrixBridgeMethods {
    openOffer: (offer: Offer) => void;
    removeOffer: (offerId: number) => void;
    closeDialog: () => void;
    sendToParent: (type: string, payload?: any) => void;
}

/**
 * Тип возвращаемого значения хука
 */
export type UseBitrixBridgeReturn = BitrixBridgeState & BitrixBridgeMethods;

/**
 * Хук для взаимодействия с родительским окном Битрикс через postMessage
 */
export const useBitrixBridge = (): UseBitrixBridgeReturn => {
    const [isReady, setIsReady] = useState(false);
    const [offers, setOffers] = useState<Offer[]>([]);
    const [apiBase, setApiBase] = useState('');
    const [productId, setProductId] = useState<number | null>(null);
    const [iblockId, setIblockId] = useState<number | null>(null);
    const [sessid, setSessid] = useState('');

    /**
     * Отправка сообщения в родительское окно
     */
    const sendToParent = useCallback((type: string, payload?: any) => {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage(
                { type, payload },
                window.location.origin
            );
        }
    }, []);

    /**
     * Открыть торговое предложение в новой вкладке
     */
    const openOffer = useCallback((offer: Offer) => {
        sendToParent('CALC_OPEN_OFFER', {
            editUrl: offer.editUrl,
            id: offer.id
        });
    }, [sendToParent]);

    /**
     * Удалить торговое предложение из списка
     */
    const removeOffer = useCallback((offerId: number) => {
        // Обновляем локальный список
        setOffers(prev => prev.filter(offer => offer.id !== offerId));
        
        // Отправляем уведомление родителю
        sendToParent('CALC_REMOVE_OFFER', { id: offerId });
    }, [sendToParent]);

    /**
     * Закрыть диалог
     */
    const closeDialog = useCallback(() => {
        sendToParent('CALC_CLOSE');
    }, [sendToParent]);

    /**
     * Обработчик сообщений от родительского окна
     */
    useEffect(() => {
        const handleMessage = (event: MessageEvent) => {
            // Проверяем origin - принимаем только сообщения с того же домена
            if (event.origin !== window.location.origin) {
                return;
            }

            const { type, payload } = event.data;

            if (!type) {
                return;
            }

            switch (type) {
                case 'BITRIX_INIT':
                    if (payload) {
                        setOffers(payload.offers || []);
                        setApiBase(payload.apiBase || '');
                        setProductId(payload.productId || null);
                        setIblockId(payload.iblockId || null);
                        setSessid(payload.sessid || '');
                        setIsReady(true);
                        
                        // Отправляем подтверждение готовности
                        sendToParent('CALC_READY');
                    }
                    break;

                case 'BITRIX_SAVE_SUCCESS':
                    console.log('Save success:', payload);
                    break;

                case 'BITRIX_SAVE_ERROR':
                    console.error('Save error:', payload);
                    break;

                case 'BITRIX_CONFIG_SAVED':
                    console.log('Config saved:', payload);
                    break;

                case 'BITRIX_CONFIG_ERROR':
                    console.error('Config error:', payload);
                    break;

                case 'BITRIX_API_RESPONSE':
                    console.log('API response:', payload);
                    break;

                default:
                    console.log('Unknown message type:', type);
            }
        };

        window.addEventListener('message', handleMessage);

        return () => {
            window.removeEventListener('message', handleMessage);
        };
    }, [sendToParent]);

    return {
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
    };
};
