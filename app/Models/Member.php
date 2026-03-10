<?php

namespace App\Models;

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Concerns\CascadesSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use CascadesSoftDeletes, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'photo',
        'code',
        'name',
        'email',
        'contact',
        'emergency_contact',
        'health_issue',
        'gender',
        'dob',
        'address',
        'country',
        'state',
        'city',
        'pincode',
        'source',
        'goal',
        'status',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = ['dob' => 'date', 'status' => Status::class];

    /**
     * The attributes that should be mutated to dates.
     * (SoftDeletes already adds deleted_at rollover.)
     *
     * @var array
     */
    protected $dates = [
        'dob',
        'deleted_at',
    ];

    /**
     * Get the subscriptions for the member.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Boot the model and add cascade delete and restore behavior.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $member): void {
            if (! $member->code) {
                $member->code = Helpers::generateLastNumber('member', Member::class, null, 'code');
            }
            Helpers::updateLastNumber('member', $member->code);
        });
    }

    /**
     * Relationship method names to cascade when deleting/restoring.
     *
     * @return list<string>
     */
    protected static function relationsToCascade(): array
    {
        return ['subscriptions'];
    }
}
