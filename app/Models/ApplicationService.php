<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationService extends Model
{
    protected $fillable = ['service', 'experience_years', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
