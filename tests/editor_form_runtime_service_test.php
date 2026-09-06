<?php
declare(strict_types=1);
namespace Prospektweb\Frontcalc\Service {
    final class StorefrontPresentationProjector {
        public function apply(array $schema, array $authoring, ?array $storefront): array {
            if (($storefront['id'] ?? '') === 'broken') throw new \RuntimeException('Broken patch');
            return $schema + ['projected' => $storefront, 'authoring' => $authoring];
        }
    }
}
namespace {
    require_once dirname(__DIR__) . '/lib/Calculator/EditorFormRuntimeService.php';
    $schema = ['version' => 2, 'fields' => [], '_form_first' => ['compileHash' => 'pinned']];
    $authoring = ['formDefinition' => ['revision' => 17]];
    $custom = ['id' => 'custom', 'name' => 'Витрина', 'presentation' => ['field_patches' => ['paper' => ['default_value' => 'offset']]]];
    $rows = \Prospektweb\Calc\Calculator\EditorFormRuntimeService::storefronts($schema, $authoring, ['items' => [$custom, ['id' => 'broken', 'name' => 'Ошибка']]]);
    if ($rows[0]['id'] !== 'BASE' || $rows[0]['name'] !== 'Базовая витрина') throw new \RuntimeException('Base must be first');
    if ($rows[1]['runtimeSchema']['projected'] !== $custom || $rows[1]['runtimeSchema']['authoring'] !== $authoring) throw new \RuntimeException('Pinned documents must reach the public projector unchanged');
    if ($rows[1]['runtimeSchema']['_form_first']['compileHash'] !== 'pinned') throw new \RuntimeException('Publication pin changed');
    if ($rows[2]['error'] !== 'Broken patch' || isset($rows[2]['runtimeSchema'])) throw new \RuntimeException('Invalid storefront must not fall back silently');
    echo "Editor form storefront projection passed\n";
}
