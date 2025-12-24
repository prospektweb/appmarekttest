<?php

namespace Prospektweb\Calc\Calculator;

/**
 * Обработчик сохранения данных из React-калькулятора
 */
class SaveHandler
{
    /**
     * Обработать запрос на сохранение
     *
     * @param array $payload
     * @return array
     */
    public function handleSaveRequest(array $payload): array
    {
        try {
            $this->validatePayload($payload);
            
            $bundleHandler = new BundleHandler();
            return $bundleHandler->saveBundle($payload);
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Валидировать входящий payload
     *
     * @param array $payload
     * @throws \Exception
     */
    private function validatePayload(array $payload): void
    {
        if (empty($payload['bundleId']) || (int)$payload['bundleId'] <= 0) {
            throw new \Exception('bundleId не указан или некорректен');
        }
        
        // linkedElements и json могут быть пустыми — это допустимо
    }
}
