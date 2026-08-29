<?php

namespace App\Services\Onboarding;

use App\Models\Restaurant;

/**
 * The single source of truth for onboarding progress (Phase 26) - used
 * by the onboarding page itself, the dashboard's setup reminder, the
 * sidebar's "Complete Setup" entry, and onboarding.show's own
 * server-side completion check, so none of them can drift into
 * disagreeing about what "done" means.
 *
 * Every query is rooted in the given Restaurant's own relationships
 * (categories()/products()/whatsAppAccounts()) - never a bare model
 * query - so a restaurant's progress can only ever reflect its own
 * data, matching this app's established tenant-boundary discipline.
 */
class GetOnboardingProgress
{
    /**
     * @return array{
     *     steps: array{profile: bool, category: bool, product: bool, whatsapp: bool},
     *     completed_steps: int,
     *     total_steps: int,
     *     all_complete: bool,
     * }
     */
    public function handle(Restaurant $restaurant): array
    {
        $steps = [
            'profile' => $this->hasCompletedProfile($restaurant),
            'category' => $restaurant->categories()->where('is_active', true)->exists(),
            'product' => $restaurant->products()->where('is_active', true)->exists(),
            'whatsapp' => $restaurant->whatsAppAccounts()->exists(),
        ];

        $completedSteps = count(array_filter($steps));
        $totalSteps = count($steps);

        return [
            'steps' => $steps,
            'completed_steps' => $completedSteps,
            'total_steps' => $totalSteps,
            'all_complete' => $completedSteps === $totalSteps,
        ];
    }

    /**
     * name/phone/address are NOT NULL columns already required at
     * registration (see RegisterRestaurantOwner), so this is true for
     * every restaurant in practice - checked explicitly rather than
     * assumed, since "the required profile fields are valid and saved"
     * is a fact about the data, not about how the row was created.
     */
    private function hasCompletedProfile(Restaurant $restaurant): bool
    {
        return filled($restaurant->name) && filled($restaurant->phone) && filled($restaurant->address);
    }
}
