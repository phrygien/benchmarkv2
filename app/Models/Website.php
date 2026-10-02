<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Website extends Model
{
    /**
     * La table associée au modèle.
     *
     * @var string
     */
    protected $table = 'web_site';

    /**
     * Les attributs assignables en masse.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'url',
        'country_code',
    ];

    /**
     * Relation avec les produits scrapés.
     */
    public function scrapedProducts()
    {
        return $this->hasMany(ScrapedProduct::class, 'web_site_id');
    }
}
