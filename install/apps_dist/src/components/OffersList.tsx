import React from 'react';
import { Offer } from '../hooks/useBitrixBridge';

/**
 * Свойства компонента OffersList
 */
export interface OffersListProps {
    offers: Offer[];
    onOpenOffer: (offer: Offer) => void;
    onRemoveOffer: (offerId: number) => void;
}

/**
 * Компонент для отображения списка торговых предложений
 * 
 * Отображает список ТП с возможностью:
 * - Открыть ТП в новой вкладке (через иконку со стрелкой)
 * - Удалить ТП из списка (через иконку X)
 */
export const OffersList: React.FC<OffersListProps> = ({
    offers,
    onOpenOffer,
    onRemoveOffer
}) => {
    if (offers.length === 0) {
        return (
            <div className="p-4 text-center text-muted-foreground">
                <p>Нет выбранных торговых предложений</p>
            </div>
        );
    }

    return (
        <div className="border rounded-lg">
            <div className="p-3 bg-muted border-b">
                <h3 className="font-semibold text-sm">
                    Торговые предложения ({offers.length})
                </h3>
            </div>
            <div className="divide-y">
                {offers.map((offer) => (
                    <div
                        key={offer.id}
                        className="flex items-center justify-between p-3 hover:bg-muted/50 transition-colors"
                    >
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2">
                                <span className="text-xs text-muted-foreground font-mono">
                                    #{offer.id}
                                </span>
                                <span className="text-sm font-medium truncate">
                                    {offer.name}
                                </span>
                            </div>
                        </div>
                        <div className="flex items-center gap-1 ml-2">
                            <button
                                onClick={() => onOpenOffer(offer)}
                                className="p-1.5 rounded hover:bg-accent hover:text-accent-foreground transition-colors"
                                title="Открыть в новой вкладке"
                                aria-label={`Открыть ${offer.name} в новой вкладке`}
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    width="16"
                                    height="16"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                >
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                                    <polyline points="15 3 21 3 21 9" />
                                    <line x1="10" y1="14" x2="21" y2="3" />
                                </svg>
                            </button>
                            <button
                                onClick={() => onRemoveOffer(offer.id)}
                                className="p-1.5 rounded hover:bg-destructive hover:text-destructive-foreground transition-colors"
                                title="Удалить из списка"
                                aria-label={`Удалить ${offer.name} из списка`}
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    width="16"
                                    height="16"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                >
                                    <line x1="18" y1="6" x2="6" y2="18" />
                                    <line x1="6" y1="6" x2="18" y2="18" />
                                </svg>
                            </button>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
};
