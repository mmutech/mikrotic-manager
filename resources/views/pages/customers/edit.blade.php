<?php

use App\Models\Customer;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::app')] #[Title('Edit customer')] class extends Component
{
    public Customer $customer;

    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $status = '';

    public function mount(Customer $customer): void
    {
        $this->authorize('update', $customer);
        $this->customer = $customer;
        $this->name = $customer->name;
        $this->email = (string) $customer->email;
        $this->phone = (string) $customer->phone;
        $this->status = $customer->status;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize('update', $this->customer);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($this->customer->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $before = $this->customer->toArray();

        $this->customer->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'status' => $validated['status'],
        ]);

        $audit->record('customer.updated', $this->customer, before: $before, after: $this->customer->fresh()->toArray(), actor: auth()->user());

        session()->flash('status', 'Customer updated successfully.');
        $this->redirectRoute('customers.show', ['customer' => $this->customer->public_id], navigate: true);
    }

    public function render()
    {
        return $this->view()->title('Edit customer');
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6">
    <div>
        <a href="{{ route('customers.show', $customer) }}" class="text-sm font-semibold text-slate-500 hover:text-slate-700">← Back to customer</a>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Edit Customer</h1>
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
            <a href="{{ route('customers.show', $customer) }}" class="mm-btn-secondary">Cancel</a>
            <button type="submit" class="mm-btn-primary">Save changes</button>
        </div>
    </form>
</div>
