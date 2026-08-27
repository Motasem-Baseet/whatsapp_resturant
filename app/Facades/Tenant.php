<?php

namespace App\Facades;

use App\Models\Restaurant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void set(Restaurant $restaurant)
 * @method static void clear()
 * @method static \App\Models\Restaurant|null get()
 * @method static int|null id()
 * @method static bool check()
 *
 * @see \App\Tenancy\TenantContext
 */
class Tenant extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenantContext::class;
    }
}
