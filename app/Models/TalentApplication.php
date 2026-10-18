<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TalentApplication extends Model
{
    protected $table = 'applications';

    protected $fillable = ['reference', 'user_id', 'applicant_type', 'name', 'email', 'phone', 'country', 'timezone', 'status', 'kyc_status', 'nda_status', 'profile', 'submitted_at'];

    protected function casts(): array
    {
        return ['profile' => 'array', 'submitted_at' => 'datetime'];
    }

    public function services()
    {
        return $this->hasMany(ApplicationService::class, 'application_id');
    }

    public function events()
    {
        return $this->hasMany(ReviewEvent::class, 'application_id');
    }

    public function scopeFiltered($q, array $f)
    {
        if ($s = $f['q'] ?? null) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$s.'%')->orWhere('email', 'like', '%'.$s.'%')->orWhere('reference', 'like', '%'.$s.'%'));
        }
        foreach (['applicant_type', 'status', 'kyc_status', 'nda_status', 'country'] as $k) {
            if ($v = $f[$k] ?? null) {
                $q->where($k, $v);
            }
        }
        if ($v = $f['service'] ?? null) {
            $q->whereHas('services', fn ($q) => $q->where('service', $v));
        }

        return $q;
    }
}
