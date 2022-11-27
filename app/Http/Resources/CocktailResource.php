<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Kami\Cocktail\Models\Cocktail
 */
class CocktailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'instructions' => e($this->instructions),
            'garnish' => e($this->garnish),
            'description' => e($this->description),
            'source' => $this->source,
            'main_image_id' => $this->images->first()->id ?? null,
            'images' => ImageResource::collection($this->images),
            'tags' => $this->tags->pluck('name'),
            'user_id' => $this->user_id,
            'glass' => new GlassResource($this->whenLoaded('glass')),
            'short_ingredients' => $this->ingredients->pluck('ingredient.name'),
            'ingredients' => CocktailIngredientResource::collection($this->ingredients),
        ];
    }
}
