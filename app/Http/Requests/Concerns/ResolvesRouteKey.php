<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Database\Eloquent\Model;

trait ResolvesRouteKey
{
    /**
     * Resolve a route parameter to its model key (if it is a model).
     */
    protected function routeKey(string $parameter): int|string
    {
        $value = $this->route($parameter);

        if ($value instanceof Model) {
            return $value->getKey();
        }

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        throw new \LogicException("Missing or invalid route parameter [{$parameter}].");
    }
}
