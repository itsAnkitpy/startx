{{--
    Every case this client has run, and what happened at each of its steps.

    The steps are listed from the frozen version the case opened on, not from what was
    done, so a step nobody ever touched still has a line here. That line is the whole
    reason the page exists: an approval that never happened is invisible everywhere else
    in the product, because every other list only holds steps that did happen.
--}}
<x-filament-panels::page>
    @php($cases = $this->cases())

    @if ($cases->isEmpty())
        <x-filament::section>
            <div class="py-8 text-center">
                <p class="text-base font-medium text-gray-950 dark:text-white">No cases yet.</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    A case appears here as soon as one starts that you can see — including anything
                    you raise yourself.
                </p>
            </div>
        </x-filament::section>
    @else
        <div class="space-y-4">
            @foreach ($cases as $case)
                @php($state = $this->stateOf($case))
                @php($steps = $this->whatHappenedOn($case))
                @php($missing = collect($steps)->where('tone', 'missed')->count())

                <x-filament::section>
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-base font-semibold text-gray-950 dark:text-white">
                                {{ $this->whoseCase($case) }}
                            </span>

                            <x-filament::badge :color="$state === 'Finished' ? 'success' : ($state === 'Cancelled' ? 'gray' : 'info')">
                                {{ $state }}
                            </x-filament::badge>

                            {{-- The failure the check at publishing exists to prevent, said
                                 out loud on the case it happened to. Without this line the
                                 case looks exactly like one that ran properly. --}}
                            @if ($missing > 0)
                                <x-filament::badge color="danger">
                                    {{ $missing === 1 ? 'A step never happened' : $missing.' steps never happened' }}
                                </x-filament::badge>
                            @endif
                        </div>

                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Started {{ $case->opened_at->format('j F Y') }} on {{ $case->template->name }},
                            version {{ $case->template->version }}.
                        </p>

                        {{-- Spaced with a plain style rather than utility classes: this
                             project has no front-end build, so only the classes Filament
                             ships compiled reach the browser. --}}
                        <div style="display: grid; row-gap: 0.5rem;">
                            @foreach ($steps as $step)
                                <div>
                                    <div class="flex flex-wrap items-baseline gap-2">
                                        <span class="text-sm font-medium text-gray-950 dark:text-white">
                                            {{ $step['sequence'] }}. {{ $step['name'] }}
                                        </span>

                                        <span
                                            @class([
                                                'text-sm',
                                                'text-gray-500 dark:text-gray-400' => $step['tone'] !== 'missed',
                                                'font-medium text-danger-600 dark:text-danger-400' => $step['tone'] === 'missed',
                                            ])
                                        >
                                            {{ $step['said'] }}
                                        </span>
                                    </div>

                                    {{-- A step that came round more than once. The line above
                                         says where it stands; these say what happened before
                                         it, oldest first, so a correction stops erasing the
                                         send-back that asked for it. --}}
                                    @if ($step['earlier'] !== [])
                                        <div style="margin-top: 0.25rem; padding-left: 1.25rem; display: grid; row-gap: 0.25rem;">
                                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                                Earlier at this step:
                                            </span>

                                            @foreach ($step['earlier'] as $pass)
                                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                                    {{ $pass }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
