<?php

namespace Prospektweb\Calc\Calculator;

/**
 * Интерфейс калькулятора себестоимости.
 */
interface CalculatorInterface
{
    /**
     * Возвращает уникальный код калькулятора.
     *
     * @return string Код калькулятора.
     */
    public function getCode(): string;

    /**
     * Возвращает отображаемое название калькулятора.
     *
     * @return string Название калькулятора.
     */
    public function getTitle(): string;

    /**
     * Возвращает ID группы калькулятора.
     *
     * @return string ID группы.
     */
    public function getGroup(): string;

    /**
     * Возвращает спецификацию опций калькулятора.
     *
     * @return array Массив опций.
     */
    public function getOptionsSpec(): array;

    /**
     * Выполняет расчёт.
     *
     * @param array $ctx     Контекст расчёта.
     * @param array $options Опции калькулятора.
     *
     * @return mixed Результат расчёта.
     */
    public function calculate(array $ctx, array $options);

    /**
     * Поддерживает ли калькулятор работу в цепочке.
     *
     * @return bool True если поддерживает.
     */
    public function supportsChain(): bool;

    /**
     * Может ли калькулятор финализировать цены (быть последним в цепочке).
     *
     * @return bool True если может финализировать.
     */
    public function supportsFinalization(): bool;

    /**
     * Может ли калькулятор изменять цены.
     *
     * @return bool True если калькулятор может изменять цены.
     */
    public function canChangePrice(): bool;

    /**
     * Является ли калькулятор системным.
     *
     * @return bool True если калькулятор системный.
     */
    public function isSystem(): bool;

    /**
     * Может ли калькулятор быть первым в цепочке.
     *
     * @return bool True если может быть первым.
     */
    public function canBeFirst(): bool;

    /**
     * Возвращает коды калькуляторов, которые должны быть перед этим.
     *
     * @return array Массив кодов.
     */
    public function requiresBefore(): array;

    /**
     * Возвращает конфигурацию полей калькулятора.
     *
     * @return array Конфигурация полей.
     */
    public function getFieldsConfig(): array;

    /**
     * Возвращает дополнительные опции калькулятора.
     *
     * @return array Дополнительные опции.
     */
    public function getExtraOptions(): array;

    /**
     * Возвращает ограничения позиции в цепочке.
     *
     * @return array Ограничения.
     */
    public function getPositionConstraints(): array;
}
