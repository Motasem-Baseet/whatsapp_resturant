<?php

namespace App\Models\Concerns;

use App\Facades\Tenant;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared foundation for Eloquent models owned by a single restaurant
 * (Customer, Category, Product, Order, Conversation, Message, ...).
 *
 * Using this trait on a model:
 *  - auto-fills restaurant_id from the current tenant when creating a
 *    record, if restaurant_id was not already set explicitly;
 *  - auto-scopes every query to the current tenant, via a global scope.
 *
 * The scope only applies while a current tenant is set (see
 * {@see \App\Tenancy\TenantContext}). With no tenant in context - e.g. a
 * console command, a queued job that hasn't set one, or deliberate
 * platform-level tooling - queries are left unfiltered rather than
 * silently returning nothing. Code that needs a tenant to be guaranteed
 * must still check for/require one explicitly; this trait does not do
 * that on its own.
 *
 * Consuming models must have a `restaurant_id` column.
 */
trait BelongsToRestaurant
{
    protected static function bootBelongsToRestaurant(): void
    {
        static::creating(function ($model) {
            if (! $model->restaurant_id && Tenant::check()) {
                $model->restaurant_id = Tenant::id();
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            if (Tenant::check()) {
                $builder->where(
                    $builder->getModel()->getTable().'.restaurant_id',
                    Tenant::id()
                );
            }
        });
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
