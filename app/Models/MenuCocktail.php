<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models;

use Brick\Money\Money;
use Brick\Money\Currency;
use Illuminate\Database\Eloquent\Model;
use Brick\Money\Exception\UnknownCurrencyException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuCocktail extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cocktail_id',
        'category_name',
        'sort',
        'price',
        'currency',
    ];

    /**
     * @return BelongsTo<Cocktail, $this>
     */
    public function cocktail(): BelongsTo
    {
        return $this->belongsTo(Cocktail::class);
    }

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function getMoney(): Money
    {
        try {
            $currency = Currency::of($this->currency);
        } catch (UnknownCurrencyException) {
            // Prior to inclusion of Money object, currency could be any string
            // To handle migration cases, we'll fallback to EUR
            $currency = 'EUR';
        }

        return Money::ofMinor($this->price, $currency);
    }
}
