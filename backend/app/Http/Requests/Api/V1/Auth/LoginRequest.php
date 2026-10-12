<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\ClientType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'client_type' => ['required', 'string', Rule::enum(ClientType::class)],
            'device_name' => ['required', 'string', 'max:255'],
            'device_id' => [
                Rule::requiredIf(fn (): bool => $this->input('client_type') === ClientType::FieldDevice->value),
                'nullable',
                'string',
                'min:8',
                'max:128',
                'regex:/^[A-Za-z0-9._:\-]+$/',
            ],
        ];
    }

    public function clientType(): ClientType
    {
        return ClientType::from((string) $this->validated('client_type'));
    }

    public function deviceId(): ?string
    {
        $deviceId = $this->validated('device_id');

        return is_string($deviceId) && $deviceId !== '' ? $deviceId : null;
    }
}
