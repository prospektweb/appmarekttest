<?php

namespace Prospektweb\Calc\Calculator;

/** Uses the public projector, but only with the selected version's documents. */
final class EditorFormRuntimeService
{
    public static function storefronts(array $schema, array $authoring, array $document): array
    {
        $base = is_array($document['base'] ?? null) ? $document['base'] : [
            'id' => 'BASE', 'name' => 'Базовая витрина',
            'presentation' => ['field_patches' => []],
        ];
        $definitions = [$base];
        foreach ((array)($document['items'] ?? []) as $item) {
            if (is_array($item) && ($item['id'] ?? '') !== 'BASE') {
                $definitions[] = $item;
            }
        }
        $projector = new \Prospektweb\Frontcalc\Service\StorefrontPresentationProjector();
        $result = [];
        foreach ($definitions as $definition) {
            $row = ['id' => (string)$definition['id'], 'name' => (string)$definition['name']];
            try {
                $row['runtimeSchema'] = $projector->apply($schema, $authoring, $definition);
            } catch (\Throwable $error) {
                // A broken optional storefront must not prevent testing the base form.
                $row['error'] = $error->getMessage();
            }
            $result[] = $row;
        }
        return $result;
    }
}
