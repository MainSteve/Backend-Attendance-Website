<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'position' => $this->position,
            'department_id' => $this->department_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'created_at' => $this->department->created_at,
                'updated_at' => $this->department->updated_at,
            ] : null,
        ];
    }
}
