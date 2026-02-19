<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use App\Models\Domain;

class NotBlacklistedDomain implements Rule
{
    protected $affiliateId;
    protected $domain;

    public function __construct($affiliateId = null)
    {
        $this->affiliateId = $affiliateId;
    }

    public function passes($attribute, $value)
    {
        if (empty($value)) {
            return true;
        }

        $this->domain = Domain::extractDomainFromEmail($value);
        
        if (empty($this->domain)) {
            return false; // Invalid email format
        }

        return !Domain::isEmailDomainBlacklisted($value, $this->affiliateId);
    }

    public function message()
    {
        return "The email domain '{$this->domain}' is not allowed. Please use a personal email address.";
    }
}