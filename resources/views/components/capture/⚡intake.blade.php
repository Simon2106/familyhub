<?php

use App\Models\Household;
use App\Services\Capture\CaptureIntake;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Adding something to the capture queue from a phone: a photo, a PDF, pasted
 * text, or a link.
 */
new class extends Component
{
    use WithFileUploads;

    public bool $open = false;

    public string $mode = 'photo';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $files = [];

    #[Validate('nullable|string|max:100000')]
    public string $text = '';

    #[Validate('nullable|url|max:2000')]
    public string $url = '';

    public ?string $error = null;

    public bool $working = false;

    /**
     * Named startCapture rather than open: a method sharing a name with a
     * public property is shadowed by it on the client, so $wire.open() would
     * resolve to the boolean and silently do nothing.
     */
    public function startCapture(string $mode = 'photo'): void
    {
        $this->reset(['files', 'text', 'url', 'error']);
        $this->mode = in_array($mode, ['photo', 'text', 'url'], true) ? $mode : 'photo';
        $this->open = true;
    }

    public function submit(): void
    {
        $this->error = null;

        try {
            match ($this->mode) {
                'photo' => $this->submitFiles(),
                'text' => $this->submitText(),
                'url' => $this->submitUrl(),
            };
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset(['open', 'files', 'text', 'url']);

        $this->dispatch('captures-changed');
        $this->dispatch('saved', message: 'Sent to be read. It will appear in Review shortly.');
    }

    protected function submitFiles(): void
    {
        $this->validate([
            'files' => 'required|array|min:1|max:10',
            // HEIC arrives from iPhone cameras and is converted before sending.
            'files.*' => 'file|max:20480|mimetypes:application/pdf,image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif',
        ]);

        $anyPdf = collect($this->files)->contains(fn ($f) => $f->getMimeType() === 'application/pdf');

        app(CaptureIntake::class)->create($anyPdf ? 'pdf' : 'photo', [
            'household' => Household::current(),
            'subject' => count($this->files) === 1
                ? $this->files[0]->getClientOriginalName()
                : count($this->files).' files',
        ], $this->files);
    }

    protected function submitText(): void
    {
        $this->validate(['text' => 'required|string|min:5|max:100000']);

        app(CaptureIntake::class)->create('text', [
            'household' => Household::current(),
            'subject' => str(trim($this->text))->limit(60)->toString(),
            'body_text' => trim($this->text),
        ]);
    }

    protected function submitUrl(): void
    {
        $this->validate(['url' => 'required|url|max:2000']);

        app(CaptureIntake::class)->fromUrl(trim($this->url), Household::current());
    }
}; ?>

<div>
    <button type="button" wire:click="startCapture('photo')"
            class="touch-target w-full rounded-2xl border-2 border-dashed border-slate-300 font-semibold text-slate-500 dark:border-slate-700 dark:text-slate-400">
        Capture something
    </button>

    @if ($open)
        <x-modal dismiss="$set('open', false)" label="Capture something">
            <div class="space-y-3 p-4">
                <h3 class="text-lg font-semibold">Capture something</h3>

                <div class="grid grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
                    @foreach (['photo' => 'Photo / PDF', 'text' => 'Text', 'url' => 'Link'] as $key => $label)
                        <button type="button" wire:click="$set('mode', '{{ $key }}')"
                                class="touch-target rounded-lg text-sm font-semibold {{ $mode === $key ? 'bg-white shadow-sm dark:bg-slate-900' : 'text-slate-500' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <form wire:submit="submit" class="space-y-3">
                    @if ($mode === 'photo')
                        <input
                            type="file"
                            wire:model="files"
                            multiple
                            accept="image/*,application/pdf"
                            capture="environment"
                            class="w-full rounded-xl border border-slate-300 p-3 text-sm dark:border-slate-600 dark:bg-slate-950"
                        >
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Photograph a letter or newsletter, or pick a PDF. iPhone HEIC photos are fine.
                        </p>
                        @error('files.*') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        @error('files') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @elseif ($mode === 'text')
                        <textarea wire:model="text" rows="8" placeholder="Paste the email or message here"
                                  class="w-full rounded-xl border border-slate-300 p-3 text-base dark:border-slate-600 dark:bg-slate-950"></textarea>
                        @error('text') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @else
                        <input wire:model="url" type="url" inputmode="url" placeholder="https://school.example/newsletter"
                               class="touch-target w-full rounded-xl border border-slate-300 px-4 text-base dark:border-slate-600 dark:bg-slate-950">
                        @error('url') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @endif

                    @if ($error)
                        <p class="rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-300">{{ $error }}</p>
                    @endif

                    <div class="flex gap-2">
                        <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">
                            <span wire:loading.remove wire:target="submit,files">Send to be read</span>
                            <span wire:loading wire:target="submit,files">Uploading…</span>
                        </button>
                        <button type="button" wire:click="$set('open', false)"
                                class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                    </div>
                </form>
            </div>
        </x-modal>
    @endif
</div>
