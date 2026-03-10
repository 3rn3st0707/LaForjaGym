<?php

namespace App\Models;

use App\Enums\Status;
use App\Models\Concerns\CascadesSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enquiry extends Model
{
    use CascadesSoftDeletes, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'contact',
        'date',
        'gender',
        'dob',
        'status',
        'address',
        'country',
        'city',
        'state',
        'pincode',
        'interested_in',
        'source',
        'goal',
        'start_by',
    ];

    protected $casts = [
        'interested_in' => 'array',
        'date' => 'date',
        'dob' => 'date',
        'start_by' => 'date',
        'status' => Status::class,
    ];

    protected $dates = ['deleted_at'];

    /**
     * Get the followUps for the enquiry.
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    /**
     * Get the user for the enquiry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship method names to cascade when deleting/restoring.
     *
     * @return list<string>
     */
    protected static function relationsToCascade(): array
    {
        return ['followUps'];
    }
}
