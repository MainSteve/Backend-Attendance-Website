<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'role' => $this->role,
            'position' => $this->position,
            'department_id' => $this->department_id,
            'department' => $this->when($this->department, new DepartmentResource($this->department)),

            // Photo profile information
            'has_photo_profile' => $this->has_photo_profile,
            'photo_profile_url' => $this->photo_profile_url,
            'photo_profile_expires_at' => $this->has_photo_profile ?
                now()->addHours(24)->toDateTimeString() : null,
        ];
    }
}
