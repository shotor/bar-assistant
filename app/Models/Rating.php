<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Rating extends Model
{
    /** @use \Illuminate\Database\Eloquent\Factories\HasFactory<\Database\Factories\RatingFactory> */
    use HasFactory;

    /**
     * @return MorphTo<Cocktail|Model, $this>
     */
    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }
}
