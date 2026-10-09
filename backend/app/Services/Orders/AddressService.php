<?php

namespace App\Services\Orders;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The customer's address book. Keeps exactly one default and never lets it run empty of one. */
class AddressService
{
    /** @param  array<string, mixed>  $data */
    public function create(User $user, array $data): Address
    {
        if ($user->addresses()->count() >= (int) config('shop.max_addresses')) {
            throw ValidationException::withMessages(['address' => 'You can save up to '.config('shop.max_addresses').' addresses. Delete one first.']);
        }

        return DB::transaction(function () use ($user, $data) {
            // The first address is the default automatically.
            $makeDefault = (bool) ($data['is_default'] ?? false) || ! $user->addresses()->exists();

            if ($makeDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            // Country is set explicitly (Malaysia only, for now) so the object we return matches what is stored.
            return $user->addresses()->create([...$data, 'country' => 'MY', 'is_default' => $makeDefault]);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Address $address, array $data): Address
    {
        return DB::transaction(function () use ($address, $data) {
            if (($data['is_default'] ?? false) === true) {
                Address::where('user_id', $address->user_id)->where('id', '!=', $address->id)->update(['is_default' => false]);
            }

            // An address cannot stop being the default on its own: another one must be chosen instead.
            if (($data['is_default'] ?? null) === false && $address->is_default) {
                unset($data['is_default']);
            }

            $address->update($data);

            return $address;
        });
    }

    public function delete(Address $address): void
    {
        DB::transaction(function () use ($address) {
            $wasDefault = $address->is_default;
            $userId = $address->user_id;
            $address->delete();

            if ($wasDefault) {
                Address::where('user_id', $userId)->orderBy('id')->first()?->update(['is_default' => true]);
            }
        });
    }
}
