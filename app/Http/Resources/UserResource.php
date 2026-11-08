<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'name' => $this->name, 'email' => $this->email,
            'username' => $this->username, 'first_name' => $this->first_name, 'last_name' => $this->last_name,
            'phone_number' => $this->phone_number, 'country' => $this->country, 'region' => $this->region,
            'address' => $this->address, 'role' => $this->role?->value, 'created_at' => $this->created_at?->toISOString()];
    }
}
