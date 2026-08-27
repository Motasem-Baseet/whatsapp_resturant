<?php

namespace App\Tenancy;

use App\Models\Restaurant;

/**
 * Holds the restaurant considered "current" for the running request, job,
 * or console command.
 *
 * This is intentionally request-agnostic: it is a plain object bound as a
 * singleton in the container, not a static/global. That makes it usable
 * anywhere the container is available (controllers, services, Livewire
 * components, jobs, artisan commands) and lets queued jobs set the tenant
 * explicitly instead of relying on any HTTP request state.
 *
 * For a normal web request, {@see \App\Http\Middleware\IdentifyTenant} sets
 * this from the authenticated user's own restaurant. Nothing else should be
 * trusted to set it.
 */
class TenantContext
{
    protected ?Restaurant $restaurant = null;

    /**
     * Set the current tenant.
     */
    public function set(Restaurant $restaurant): void
    {
        $this->restaurant = $restaurant;
    }

    /**
     * Clear the current tenant, if any.
     */
    public function clear(): void
    {
        $this->restaurant = null;
    }

    /**
     * Get the current tenant, if any.
     */
    public function get(): ?Restaurant
    {
        return $this->restaurant;
    }

    /**
     * Get the current tenant's id, if any.
     */
    public function id(): ?int
    {
        return $this->restaurant?->id;
    }

    /**
     * Determine whether a current tenant is set.
     */
    public function check(): bool
    {
        return $this->restaurant !== null;
    }
}
