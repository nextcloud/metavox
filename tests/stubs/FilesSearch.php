<?php

declare(strict_types=1);

// Stand-ins for Nextcloud's search query classes, which only exist inside a
// Nextcloud install. Constructors follow NC 35 (lib/private/Files/Search).

namespace OC\Files\Search;

use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

if (!class_exists(SearchComparison::class)) {
    class SearchComparison implements ISearchComparison {
        public function __construct(
            private string $type,
            private string $field,
            private \DateTime|int|string|bool|array $value,
            private string $extra = '',
        ) {
        }

        public function getType(): string {
            return $this->type;
        }

        public function getField(): string {
            return $this->field;
        }

        public function getExtra(): string {
            return $this->extra;
        }

        public function getValue(): string|int|bool|\DateTime|array {
            return $this->value;
        }

        public function getQueryHint(string $name, $default) {
            return $default;
        }

        public function setQueryHint(string $name, $value): void {
        }
    }
}

if (!class_exists(SearchQuery::class)) {
    class SearchQuery implements ISearchQuery {
        public function __construct(
            private ISearchOperator $searchOperation,
            private int $limit,
            private int $offset,
            private array $order,
            private ?IUser $user = null,
            private bool $limitToHome = false,
            private array $selectFields = [],
        ) {
        }

        public function getSearchOperation() {
            return $this->searchOperation;
        }

        public function getLimit() {
            return $this->limit;
        }

        public function getOffset() {
            return $this->offset;
        }

        public function getOrder() {
            return $this->order;
        }

        public function getUser() {
            return $this->user;
        }

        public function limitToHome(): bool {
            return $this->limitToHome;
        }

        public function getSelectFields(): array {
            return $this->selectFields;
        }
    }
}
