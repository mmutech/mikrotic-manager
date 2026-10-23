<?php

use App\Models\Customer;
use App\Services\AuditLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Add customer')] class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $status = 'active';

    public function mount(): void
    {
        $this->authorize('create', Customer::class);
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('create', Customer::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $customer = Customer::create([
            'code' => 'CUST-'.strtoupper(Str::random(8)),
            'name' => $validated['name'],
            'email' => $validated['email'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'status' => $validated['status'],
            'created_by' => auth()->id(),
        ]);

        $audit->record('customer.created', $customer, after: $customer->toArray(), actor: auth()->user());

        session()->flash('status', 'Customer created successfully.');
        $this->redirectRoute('customers.show', ['customer' => $customer->public_id], navigate: true);
    }

    public function render()
    {
        return $this->view()->title('Add customer');
    }
};
?>

<div class="mx-auto space-y-6">
    <div>
        <a href="{{ route('customers.index') }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to customers</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Add Customer</h1>
    </div>

    <form wire:submit="save" class="mm-card space-y-5 p-6">
        <div>
            <label class="mm-label">Full name *</label>
            <input type="text" wire:model="name" class="mm-input">
            @error('name') <p class="mm-error">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mm-label">Email</label>
                <input type="email" wire:model="email" class="mm-input">
                @error('email') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mm-label">Phone</label>
                <input type="text" wire:model="phone" class="mm-input">
                @error('phone') <p class="mm-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="mm-label">Status *</label>
            <select wire:model="status" class="mm-input">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            @error('status') <p class="mm-error">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('customers.index') }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Add customer</button>
        </div>
    </form>
</div>
