<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A tiny key/value store for singleton panel state that must outlive the cache
 * -- currently just the lockdown flag. The value column is JSON so a setting can
 * hold structured data (who/when) rather than only a scalar.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
