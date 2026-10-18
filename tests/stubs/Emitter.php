<?php

declare(strict_types=1);

namespace OC\Hooks;

/** IRootFolder extends this private NC interface; without it we can't mock it. */
if (!interface_exists(Emitter::class)) {
    interface Emitter {
        public function listen($scope, $method, callable $callback);

        public function removeListener($scope = null, $method = null, ?callable $callback = null);
    }
}
