<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
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
            'title' => $this->title,
            'content' => $this->content,
            'importance_level' => $this->importance_level,
            'importance_level_text' => $this->importance_level_text,
            'created_by' => $this->created_by,
            'expires_at' => $this->expires_at->toISOString(),
            'expires_at_human' => $this->expires_at->diffForHumans(),
            'days_remaining' => $this->days_remaining,
            'is_active' => $this->is_active,
            'is_valid' => $this->isValid(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            
            // Relationships
            'departments' => $this->when($this->relationLoaded('departments'),
                DepartmentResource::collection($this->departments)
            ),
            'creator' => $this->when($this->relationLoaded('creator'),
                new UserResource($this->creator)
            ),
        ];
    }
}
