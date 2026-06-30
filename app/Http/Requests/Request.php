<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest as BaseFormRequest;

/**
 * Base FormRequest with typed accessors. FormRequests cannot use the
 * #[CurrentUser] attribute the way invokable controllers do, so `user()` is
 * narrowed to the concrete User here, and `routeModel()` gives type-safe access
 * to bound route models.
 */
abstract class Request extends BaseFormRequest
{
    final public function user($guard = null): User
    {
        $user = parent::user($guard);

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function routeModel(string $key, string $class): object
    {
        $model = $this->route($key);

        abort_unless($model instanceof $class, 404);

        return $model;
    }
}
