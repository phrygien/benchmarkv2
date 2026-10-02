<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScrapedProduct extends Model
{
    /**
     * La table associée au modèle.
     *
     * @var string
     */
    protected $table = 'scraped_product';

    /**
     * Les attributs assignables en masse.
     *
     * @var array
     */
    protected $fillable = [
        'web_site_id',
        'vendor',
        'image_url',
        'name',
        'type',
        'variation',
        'prix_ht',
        'currency',
        'url',
        'scrap_reference_id',
        'ean',
    ];

    /**
     * Conversion automatique des types.
     *
     * @var array
     */
    protected $casts = [
        'web_site_id' => 'integer',
        'scrap_reference_id' => 'integer',
        'prix_ht' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relation avec le site web (si vous avez un modèle WebSite).
     */
    public function website()
    {
        return $this->belongsTo(Website::class, 'web_site_id');
    }
}
