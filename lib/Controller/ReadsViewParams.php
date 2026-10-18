<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\ViewColumnFormat;
use OCA\MetaVox\L10n\Translatable;

/**
 * The parameters of a view to create or update. A malformed one is a 400,
 * instead of a database error or a view that silently loses its columns.
 */
trait ReadsViewParams {

    /**
     * @return array{name: string, is_default: bool, columns: array, filters: array, sort_field: ?string, sort_order: ?string}
     * @throws RequestException|ApiValidationException
     */
    protected function viewParams(): array {
        $name = $this->request->getParam('name');
        if (!is_string($name)) {
            // TRANSLATORS Error message shown to the user when a view is saved without a name; a view is a saved combination of columns, filters and sort order
            throw RequestException::invalid(Translatable::t('View name is required'));
        }
        // Blank the way the browser sees it, so a name of only no-break spaces is blank too.
        $trimmed = ViewColumnFormat::jsTrim($name);
        if ($trimmed === null || str_contains($name, "\0")) {
            throw new ApiValidationException(['name' => ViewColumnFormat::MSG_INVALID]);
        }
        if ($trimmed === '') {
            // TRANSLATORS Error message shown to the user when a view is saved without a name; a view is a saved combination of columns, filters and sort order
            throw RequestException::invalid(Translatable::t('View name is required'));
        }
        if (mb_strlen($name, 'UTF-8') > 100) {
            // TRANSLATORS Error message shown to the user when the name of a view is too long; a view is a saved combination of columns, filters and sort order
            throw RequestException::invalid(Translatable::t('A view name can have at most 100 characters'));
        }

        return [
            'name' => $name,
            // "false" is no; (bool) made it yes, and the old default lost its star.
            'is_default' => $this->boolParam('is_default', false),
            'columns' => $this->viewPart('columns'),
            'filters' => $this->viewPart('filters'),
            'sort_field' => $this->sortFieldParam(),
            'sort_order' => $this->sortOrderParam(),
        ];
    }

    /** The new place of a view, or null to keep it. */
    protected function positionParam(): ?int {
        $position = $this->request->getParam('position');
        if (is_string($position) && preg_match('/^[0-9]{1,10}\z/', $position)) {
            $position = (int)$position;
        }
        if ($position !== null && (!is_int($position) || $position < 0 || $position > 2147483647)) {
            throw new ApiValidationException(['position' => ViewColumnFormat::MSG_INVALID]);
        }
        return $position;
    }

    // Missing means empty, as before; null is refused: it would empty the view.
    private function viewPart(string $key): array {
        if (!array_key_exists($key, $this->request->getParams())) {
            return [];
        }
        $value = $this->request->getParam($key);
        if (!is_array($value)) {
            throw new ApiValidationException([$key => ViewColumnFormat::MSG_INVALID]);
        }
        return $value;
    }

    private function sortFieldParam(): ?string {
        $field = $this->request->getParam('sort_field');
        if ($field === null) {
            return null;
        }
        if (!is_string($field) || ViewColumnFormat::jsTrim($field) === null || str_contains($field, "\0") || mb_strlen($field, 'UTF-8') > 100) {
            throw new ApiValidationException(['sort_field' => ViewColumnFormat::MSG_INVALID]);
        }
        return $field;
    }

    private function sortOrderParam(): ?string {
        $order = $this->request->getParam('sort_order');
        if ($order === null || $order === '') {
            return $order;
        }
        if (!is_string($order) || !in_array(strtolower($order), ['asc', 'desc'], true)) {
            throw new ApiValidationException(['sort_order' => ViewColumnFormat::MSG_INVALID]);
        }
        return $order;
    }
}
